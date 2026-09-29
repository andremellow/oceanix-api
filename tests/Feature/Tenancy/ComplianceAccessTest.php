<?php

use App\Actions\Auth\AuthenticateSocialLogin;
use App\Data\SocialIdentity;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ComplianceEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\CompanyComplianceAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    $this->withoutVite();
});
it('SC-02 blocks the next employee and administrator request without logging out', function ($admin) {
    $user = $admin ? adminUser() : employeeUser();
    $company = currentCompany();
    $this->actingAs($user)->get('/c/'.$company->slug.'/dashboard')->assertOk();
    $company->update(['compliance_access_enabled' => false]);
    $this->get('/c/'.$company->slug.'/dashboard')->assertForbidden();
    $this->assertAuthenticatedAs($user);
    Http::assertNothingSent();
})->with([false, true]);
it('SC-02 checks a real checksum-verified Livewire snapshot before hydration', function ($admin) {
    $user = $admin ? adminUser() : employeeUser();
    $company = currentCompany();
    $response = $this->actingAs($user)->get('/c/'.$company->slug.'/dashboard')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $url = Livewire::getUpdateUri();
    $body = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]];
    $this->postJson($url, $body, ['X-Livewire' => 'true'])->assertOk();
    $company->update(['compliance_access_enabled' => false]);
    Livewire::flushState();
    $this->postJson($url, $body, ['X-Livewire' => 'true'])->assertForbidden();
})->with([false, true]);
it('SC-03 SC-04 denies tenant login uploads and stale model entry while preserving public recovery', function () {
    $company = currentCompany();
    $stale = $company->fresh();
    $company->update(['compliance_access_enabled' => false]);
    expect(fn () => app(CompanyComplianceAccess::class)->assertEnabled($stale))->toThrow(HttpException::class);
    $this->withSession(['company_id' => $company->id])->get('/login/'.$company->slug)->assertForbidden();
    $this->get('/login')->assertOk();
    $this->post(route('livewire.upload-file'))->assertForbidden();
});

it('SC-03 denies identity linking after disable before any account or user writes', function () {
    $user = employeeUser();
    $company = currentCompany();
    $before = $user->getAttributes();
    $count = Account::count();
    $company->update(['compliance_access_enabled' => false]);
    expect(fn () => app(AuthenticateSocialLogin::class)->handle(new SocialIdentity('workos', 'user_access', $user->email, 'Changed', emailVerified: true)))->toThrow(HttpException::class);
    expect($user->fresh()->getAttributes())->toBe($before)->and(Account::count())->toBe($count);
});
it('SC-04 SC-05 keeps global recovery usable while blocking targeted platform entry', function () {
    $company = currentCompany();
    $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active']);
    $company->update(['compliance_access_enabled' => false]);
    $this->withSession(['platform_account_id' => $account->id, 'company_id' => $company->id])->get('/platform')->assertOk();
    $this->get('/platform/companies/'.$company->id)->assertOk();
    $this->post('/platform/companies/'.$company->id.'/enter')->assertForbidden();
});

it('SC-04 blocks uploads playback and event writes after positive controls', function () {
    Storage::fake('tmp-for-tests');
    config(['livewire.temporary_file_upload.disk' => 'tmp-for-tests']);
    $company = currentCompany();
    [$assignment,$lesson] = trainableAssignment();
    $this->actingAs($assignment->user);
    fakeCloudflarePlayback();
    $upload = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5));
    $this->post($upload, ['files' => [UploadedFile::fake()->create('control.txt', 1)]])->assertOk();
    $this->postJson(route('my-training.playback', ['assignment' => $assignment, 'lesson' => $lesson]))->assertOk();
    $events = ComplianceEvent::count();
    $files = Storage::disk('tmp-for-tests')->allFiles();
    $requests = Http::recorded()->count();
    $company->update(['compliance_access_enabled' => false]);
    $this->post($upload, ['files' => [UploadedFile::fake()->create('denied.txt', 1)]])->assertForbidden();
    $this->postJson(route('my-training.playback', ['assignment' => $assignment, 'lesson' => $lesson]))->assertForbidden();
    $this->postJson(route('my-training.events', ['assignment' => $assignment, 'lesson' => $lesson]), ['events' => [['uuid' => (string) Str::uuid(), 'event_type' => 'video.progressed', 'occurred_at' => now()->toIso8601String(), 'position_seconds' => 5]]])->assertForbidden();
    expect(ComplianceEvent::count())->toBe($events)->and(Storage::disk('tmp-for-tests')->allFiles())->toBe($files)->and(Http::recorded()->count())->toBe($requests);
});

it('SC-02 SC-05 checks every verified component in a mixed global and tenant batch', function () {
    $company = currentCompany();
    $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active']);
    $user = adminUser();
    $user->update(['account_id' => $account->id]);
    $this->actingAs($user);
    $snapshots = [];
    foreach (['/platform', '/c/'.$company->slug.'/dashboard'] as $path) {
        Livewire::flushState();
        $response = $this->get($path)->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshots[] = html_entity_decode($matches[1], ENT_QUOTES);
    }
    $company->update(['compliance_access_enabled' => false]);
    Livewire::flushState();
    $components = array_map(fn ($snapshot) => ['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]], $snapshots);
    $this->postJson(Livewire::getUpdateUri(), ['components' => [$components[0]]], ['X-Livewire' => 'true'])->assertOk();
    Livewire::flushState();
    $this->postJson(Livewire::getUpdateUri(), ['components' => $components], ['X-Livewire' => 'true'])->assertForbidden();
});

it('SC-04 SC-05 rejects a disabled switch target without changing B identity session context or evidence', function () {
    $a = currentCompany();
    $account = Account::factory()->create();
    $userA = User::factory()->create(['company_id' => $a->id, 'account_id' => $account->id]);
    $b = Company::factory()->create();
    app(TenantContext::class)->set($b);
    $userB = User::factory()->create(['company_id' => $b->id, 'account_id' => $account->id]);
    $this->actingAs($userB)->withSession(['company_id' => $b->id])->post(route('company.switch', ['targetCompany' => $a]))->assertRedirect();
    expect(auth()->id())->toBe($userA->id);
    $this->post(route('company.switch', ['targetCompany' => $b]))->assertRedirect();
    expect(auth()->id())->toBe($userB->id);
    $a->update(['compliance_access_enabled' => false]);
    $before = ['company' => $b->fresh()->getAttributes(), 'person' => $userB->fresh()->getAttributes(), 'audit' => AuditLog::withoutGlobalScopes()->get()->toJson()];
    $this->post(route('company.switch', ['targetCompany' => $a]))->assertForbidden();
    expect(auth()->id())->toBe($userB->id)->and(session('company_id'))->toBe($b->id)->and(app(TenantContext::class)->id())->toBe($b->id);
    $this->get('/c/'.$b->slug.'/dashboard')->assertOk();
    expect($b->fresh()->getAttributes())->toBe($before['company'])->and($userB->fresh()->getAttributes())->toBe($before['person'])->and(AuditLog::withoutGlobalScopes()->get()->toJson())->toBe($before['audit']);
});

it('SC-02 rejects actual serialized assessment writes before domain and audit effects for employee and admin', function (bool $admin) {
    [$assignment,$lesson,$question] = trainableAssignment(['content_markdown' => 'Assessment only']);
    $user = $assignment->user;
    if ($admin) {
        $role = Role::firstOrCreate(['key' => 'admin'], ['name' => 'Administrator', 'is_protected' => true]);
        $user->roles()->attach($role);
    }
    $company = currentCompany();
    $this->actingAs($user);
    $response = $this->get(route('my-training.lesson', ['assignment' => $assignment, 'lesson' => $lesson]))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $wrong = $question->options()->where('is_correct', false)->firstOrFail();
    $body = ['components' => [['snapshot' => $snapshot, 'updates' => ['selected.'.$question->id => (string) $wrong->id], 'calls' => [['path' => '', 'method' => 'answer', 'params' => [$question->id]]]]]];
    Livewire::flushState();
    $result = $this->postJson(Livewire::getUpdateUri(), $body, ['X-Livewire' => 'true'])->assertOk();
    expect(DB::table('question_attempts')->count())->toBe(1);
    $body['components'][0]['snapshot'] = $result->json('components.0.snapshot');
    $tables = ['question_attempts', 'lesson_attempts', 'course_attempts', 'lesson_progress', 'user_training_assignments', 'compliance_events', 'audit_logs'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);
    $company->update(['compliance_access_enabled' => false]);
    Livewire::flushState();
    $this->postJson(Livewire::getUpdateUri(), $body, ['X-Livewire' => 'true'])->assertForbidden();
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    Http::assertNothingSent();
})->with([false, true]);
