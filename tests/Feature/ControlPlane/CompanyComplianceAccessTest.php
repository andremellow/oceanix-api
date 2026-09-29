<?php

use App\Actions\Auth\AuthenticateSocialLogin;
use App\Data\SocialIdentity;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\SocialLoginProviderException;
use App\Models\AccountCompanyBinding;
use App\Models\Certificate;
use App\Models\CompanyAccessReceipt;
use App\Models\ServicePrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function accessFixture(): array
{
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_access']);
    $uuid = (string) Str::uuid();
    AccountCompanyBinding::create(['account_company_uuid' => $uuid, 'compliance_company_id' => $company->id, 'workos_organization_id' => 'org_access']);
    $principal = ServicePrincipal::create(['name' => 'Access fixture', 'product' => 'compliance', 'environment' => app()->environment(), 'active' => true]);
    $key = (string) Str::uuid();

    return [$company, '/api/control-plane/v1/companies/'.$uuid.'/compliance-access', $principal, ['workos_organization_id' => 'org_access', 'compliance_company_public_id' => $company->public_id, 'enabled' => false, 'version' => 1, 'actor_workos_user_id' => 'user_operator', 'correlation_id' => $key], $key];
}
it('SC-01 SC-13 SC-15 applies and replays immutable ordered access receipts', function () {
    Http::fake();
    [$company,$url,$principal,$payload,$key] = accessFixture();
    $this->withToken($principal->createToken('access', ['companies:access'])->plainTextToken)->withHeader('Idempotency-Key', $key);
    $first = $this->putJson($url, $payload)->assertOk()->assertJsonPath('enabled', false)->json();
    expect($company->fresh()->compliance_access_enabled)->toBeFalse();
    $next = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $next)->putJson($url, [...$payload, 'enabled' => true, 'version' => 2, 'correlation_id' => $next])->assertOk();
    $this->withHeader('Idempotency-Key', $key)->putJson($url, $payload)->assertExactJson($first);
    expect($company->fresh()->compliance_access_enabled)->toBeTrue()->and(CompanyAccessReceipt::count())->toBe(2);
    Http::assertNothingSent();
});
it('SC-12 refuses wildcard bearer and malformed commands', function () {
    [$company,$url,$principal,$payload,$key] = accessFixture();
    $this->withToken($principal->createToken('wildcard', ['*'])->plainTextToken)->withHeader('Idempotency-Key', $key)->putJson($url, $payload)->assertForbidden();
    $this->withToken($principal->createToken('access', ['companies:access'])->plainTextToken)->putJson($url, [...$payload, 'enabled' => 'false'])->assertUnprocessable();
    expect($company->fresh()->compliance_access_enabled)->toBeTrue();
});

it('SC-12 rejects identity conflicts skipped versions and changed immutable keys', function () {
    [$company,$url,$principal,$payload,$key] = accessFixture();
    $this->withToken($principal->createToken('access', ['companies:access'])->plainTextToken)->withHeader('Idempotency-Key', $key);
    foreach ([['workos_organization_id' => 'org_other'], ['compliance_company_public_id' => (string) Str::uuid()], ['version' => 2]] as $change) {
        $this->putJson($url, [...$payload, ...$change])->assertConflict();
    }
    foreach ([['extra' => true], ['version' => '1'], ['version' => 0], ['version' => 2147483648], ['enabled' => 1], ['correlation_id' => (string) Str::uuid()]] as $change) {
        $this->putJson($url, [...$payload, ...$change])->assertUnprocessable();
    }
    expect(CompanyAccessReceipt::count())->toBe(0)->and($company->fresh()->compliance_access_enabled)->toBeTrue();
    $this->putJson($url, $payload)->assertOk();
    $this->putJson($url, [...$payload, 'enabled' => true])->assertConflict();
    expect(fn () => CompanyAccessReceipt::sole()->update(['response_status' => 201]))->toThrow(LogicException::class);
});
it('SC-07 SC-08 preserves suspension identity and training evidence through a full cycle', function () {
    Http::fake();
    [$company,$url,$principal,$payload,$key] = accessFixture();
    [$assignment, $lesson] = trainableAssignment();
    watch($assignment, $lesson, 20);
    Certificate::factory()->create(['user_id' => $assignment->user_id, 'assignment_id' => $assignment->id, 'course_id' => $assignment->course_id, 'course_version_id' => $assignment->course_version_id]);
    grantPermissions($assignment->user, [Permission::CoursesView]);
    $company->update(['status' => 'suspended']);
    $tables = ['users', 'roles', 'role_user', 'permission_role', 'courses', 'course_versions', 'user_training_assignments', 'compliance_events', 'lesson_progress', 'certificates'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);
    $this->withToken($principal->createToken('access', ['companies:access'])->plainTextToken)->withHeader('Idempotency-Key', $key)->putJson($url, $payload)->assertOk();
    $next = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $next)->putJson($url, [...$payload, 'enabled' => true, 'version' => 2, 'correlation_id' => $next])->assertOk();
    expect($company->fresh()->status)->toBe('suspended')->and($company->fresh()->public_id)->toBe($company->public_id);
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    Http::assertNothingSent();
});
it('SC-12 rejects human expired inactive and wrong environment principals', function () {
    [$company,$url,$principal,$payload,$key] = accessFixture();
    $this->withHeader('Idempotency-Key', $key)->putJson($url, $payload)->assertUnauthorized();
    $this->withToken(adminUser()->createToken('human', ['companies:access'])->plainTextToken)->putJson($url, $payload)->assertForbidden();
    $this->withToken($principal->createToken('expired', ['companies:access'], now()->subMinute())->plainTextToken)->putJson($url, $payload)->assertUnauthorized();
    $token = $principal->createToken('valid', ['companies:access'])->plainTextToken;
    $principal->update(['active' => false]);
    $this->withToken($token)->putJson($url, $payload)->assertForbidden();
    $principal->update(['active' => true, 'environment' => 'wrong']);
    $this->putJson($url, $payload)->assertForbidden();
    expect(CompanyAccessReceipt::count())->toBe(0)->and($company->fresh()->compliance_access_enabled)->toBeTrue();
});

it('SC-06 SC-07 SC-08 restores only product eligibility while retaining effective grants and independent restrictions', function (string $restriction) {
    $this->withoutVite();
    Http::fake();
    [$company,$url,$principal,$payload,$key] = accessFixture();
    $user = employeeUser();
    $profile = grantPermissions($user, [Permission::CoursesView]);
    $this->actingAs($user)->get('/c/'.$company->slug.'/courses')->assertOk();
    expect(Gate::forUser($user)->allows('courses.view'))->toBeTrue();
    if ($restriction === 'company') {
        $company->update(['status' => 'suspended']);
    }
    if ($restriction === 'user') {
        $user->update(['status' => UserStatus::Suspended]);
    }
    if ($restriction === 'grant') {
        $profile->update(['archived_at' => now()]);
    }
    $grants = DB::table('permission_role')->get()->toJson();
    expect(DB::table('permission_role')->count())->toBeGreaterThan(0);
    $this->withToken($principal->createToken('access', ['companies:access'])->plainTextToken)->withHeader('Idempotency-Key', $key)->putJson($url, $payload)->assertOk();
    $next = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $next)->putJson($url, [...$payload, 'enabled' => true, 'version' => 2, 'correlation_id' => $next])->assertOk();
    expect(DB::table('permission_role')->get()->toJson())->toBe($grants)->and($company->fresh()->compliance_access_enabled)->toBeTrue();
    if ($restriction === 'company') {
        $this->get('/c/'.$company->slug.'/courses')->assertNotFound();
        expect($company->fresh()->status)->toBe('suspended');
    } elseif ($restriction === 'user') {
        $before = $user->fresh()->getAttributes();
        expect(fn () => app(AuthenticateSocialLogin::class)->handle(new SocialIdentity('workos', 'user_reenabled', $user->email, emailVerified: true)))->toThrow(SocialLoginProviderException::class);
        expect($user->fresh()->getAttributes())->toBe($before)->and($user->fresh()->status)->toBe(UserStatus::Suspended);
    } elseif ($restriction === 'grant') {
        expect(Gate::forUser($user)->allows('courses.view'))->toBeFalse();
        $this->get('/c/'.$company->slug.'/courses')->assertForbidden();
    } else {
        expect(Gate::forUser($user)->allows('courses.view'))->toBeTrue();
        $this->get('/c/'.$company->slug.'/courses')->assertOk();
    }
    Http::assertNothingSent();
})->with(['eligible', 'company', 'user', 'grant']);
