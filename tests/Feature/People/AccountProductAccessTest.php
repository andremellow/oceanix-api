<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Actions\People\ChangePersonProductAccess;
use App\Enums\ComplianceEventType;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\PersonProductAccess;
use App\Models\Role;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use App\Services\People\PersonProductAccess as ProductAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccountIntegrationFixture as Fixture;

it('removes only confirmed product access and preserves local records after uncertain response', function () {
    [$company, $person, $principal, $token, $envelope] = Fixture::receiver();
    app(ApplyProductOperation::class)->handle($principal, $envelope);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->forceFill(['workos_user_id' => 'user_local_removal_actor'])->save();
    $before = $person->fresh()->getRawOriginal();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    Http::fake(['account.example.test/*' => Http::failedConnection()]);
    $operation = app(ChangePersonProductAccess::class)->handle($actor, $person, (string) Str::uuid());
    expect($operation->state)->toBe('unknown')->and(app(ProductAccess::class)->allows($person))->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && isset($request->data()['actor_assertion']['signature']));
    expect($person->fresh()->getRawOriginal())->toBe($before);
    // Receiver delivery succeeds independently while the original HTTP acknowledgement was lost.
    $block = array_replace($envelope, ['operation_id' => (string) Str::uuid(), 'kind' => 'person.access', 'resource_sequence' => 2]);
    $block['payload']['enabled'] = false;
    app(ApplyProductOperation::class)->handle($principal, $block);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $receipt = array_intersect_key($operation->payload, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['enabled' => false]];
    Http::fake(['account.example.test/*' => Http::response($receipt)]);
    $recovered = app(ChangePersonProductAccess::class)->handle($actor, $person, $operation->id);
    expect($recovered->state)->toBe('confirmed')->and(app(ProductAccess::class)->allows($person))->toBeFalse()
        ->and(PersonProductAccess::where('user_id', $person->id)->sole()->version)->toBe(2)
        ->and($person->fresh()->getRawOriginal())->toBe($before);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('denies product removal without permission and for a foreign actor', function () {
    [$company, $person, $principal, $token, $envelope] = Fixture::receiver();
    app(ApplyProductOperation::class)->handle($principal, $envelope);
    $actor = employeeUser();
    $actor->forceFill(['workos_user_id' => 'user_denied_removal'])->save();
    expect(fn () => app(ChangePersonProductAccess::class)->handle($actor, $person, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(PersonProductAccess::where('user_id', $person->id)->sole()->confirmed_enabled)->toBeTrue();
    $foreign = Company::factory()->create();
    $actor->forceFill(['company_id' => $foreign->id])->save();
    expect(fn () => app(ChangePersonProductAccess::class)->handle($actor, $person, (string) Str::uuid()))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('preserves local roles assignments certificates and append-only evidence on block and reenable', function () {
    seedAccessCatalog();
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    $person->roles()->syncWithoutDetaching(Role::where('key', 'employee')->firstOrFail()->id);
    $requirement = invitationRequirementFixture();
    $assignment = UserTrainingAssignment::factory()->forCourse($requirement->course)->completed()->create(['user_id' => $person->id]);
    $certificate = Certificate::factory()->create(['user_id' => $person->id, 'assignment_id' => $assignment->id, 'course_id' => $assignment->course_id, 'course_version_id' => $assignment->course_version_id]);
    $event = app(ComplianceEventRecorder::class)->record(ComplianceEventType::CourseCompleted, $person->id, ['assignment_id' => $assignment->id, 'course_version_id' => $assignment->course_version_id]);
    $person->forceFill(['status' => UserStatus::Suspended, 'first_access_at' => now()->subDays(10)])->save();
    $tables = ['users', 'user_training_assignments', 'certificates', 'compliance_events', 'role_user'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->get()->toJson();
    }
    app(ApplyProductOperation::class)->handle($principal, $input);
    foreach ([false, true] as $index => $enabled) {
        $change = array_replace($input, ['operation_id' => (string) Str::uuid(), 'kind' => 'person.access', 'resource_sequence' => $index + 2]);
        $change['payload']['enabled'] = $enabled;
        app(ApplyProductOperation::class)->handle($principal, $change);
        expect(PersonProductAccess::where('user_id', $person->id)->sole()->confirmed_enabled)->toBe($enabled);
        foreach ($tables as $table) {
            expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
        }
    }
    expect($person->fresh()->status)->toBe(UserStatus::Suspended);
    Http::assertNothingSent();
});

it('does not inherit Account platform authority as product permission', function () {
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    app(ApplyProductOperation::class)->handle($principal, $input);
    $actor = employeeUser();
    $actor->forceFill(['workos_user_id' => 'user_platform_product_denied', 'account_id' => Account::factory()->platformAdmin()->create()->id])->save();
    expect(fn () => app(ChangePersonProductAccess::class)->handle($actor, $person, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
    grantPermissions($actor, [Permission::PeopleManage]);
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    Http::fake(['account.example.test/*' => Http::failedConnection()]);
    expect(app(ChangePersonProductAccess::class)->handle($actor, $person, (string) Str::uuid())->state)->toBe('unknown');
    Http::assertSentCount(1);
});
