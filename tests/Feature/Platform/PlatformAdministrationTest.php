<?php

use Andremellow\Tasks\Models\Task;
use Andremellow\Tasks\Models\TaskComment;
use Andremellow\Tasks\Models\TaskType;
use App\Actions\Auth\AuthenticatePlatformAccount;
use App\Data\SocialIdentity;
use App\Http\Middleware\AuthenticatePlatformTaskUser;
use App\Http\Middleware\EnsureUserIsPlatformAdmin;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PlatformTaskUser;
use App\Models\User;
use App\Services\Platform\PlatformOverview;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

it('denies the platform area to a company administrator', function (): void {
    $this->actingAs(adminUser())
        ->get(route('platform.dashboard'))
        ->assertForbidden();
});

it('offers platform sign-in instead of forbidding an unauthenticated visitor', function (): void {
    auth()->logout();
    session()->forget('platform_account_id');

    $this->get(route('platform.dashboard'))
        ->assertRedirect(route('platform.login'));

    $this->get(route('platform.login'))
        ->assertOk()
        ->assertSee(__('Sign in as platform administrator'));
});

it('allows a platform administrator to view cross-company metrics', function (): void {
    Company::factory()->create();
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);

    $this->actingAs($user)
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertSee(__('Global overview'))
        ->assertSee(__('Sign out'))
        ->assertSee(route('platform.logout'), escape: false);
});

it('authenticates a platform administrator in the tasks area as a normal user', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.tasks.index'))
        ->assertOk();

    expect(auth()->user())
        ->toBeInstanceOf(User::class)
        ->and(auth()->user()?->getKey())->toBe($user->id)
        ->and(Gate::forUser(auth()->user())->allows('create', Task::class))->toBeTrue()
        ->and(Gate::forUser(auth()->user())->allows('update', new Task))->toBeTrue()
        ->and(Gate::forUser(auth()->user())->allows('delete', new Task))->toBeTrue();
});

it('keeps platform task authentication on Livewire updates and lists each account once', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    User::factory()->create([
        'account_id' => $account->id,
        'company_id' => Company::factory()->create()->id,
        'email' => $account->email,
    ]);
    User::factory()->create([
        'account_id' => $account->id,
        'company_id' => Company::factory()->create()->id,
        'email' => $account->email,
    ]);

    $middleware = app(PersistentMiddleware::class)->getPersistentMiddleware();

    expect($middleware)
        ->toContain(EnsureUserIsPlatformAdmin::class)
        ->toContain(AuthenticatePlatformTaskUser::class)
        ->and(PlatformTaskUser::query()->where('account_id', $account->id)->count())->toBe(1);
});

it('lets a platform administrator add a persistent comment to a task', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $user = User::factory()->create([
        'account_id' => $account->id,
        'email' => $account->email,
    ]);
    $type = TaskType::query()->create([
        'name' => 'Improvement',
        'slug' => 'improvement',
    ]);
    $task = Task::query()->create([
        'task_type_id' => $type->id,
        'created_by' => $user->id,
        'title' => 'Improve the dashboard',
        'description' => 'Match the Control Center design.',
        'priority' => 'medium',
        'status' => 'backlog',
    ]);

    $component = Livewire\Livewire::actingAs($user)
        ->test('tasks::tasks.show', ['task' => $task, 'embedded' => true]);

    $component
        ->set('newComment', '   ')
        ->call('addComment')
        ->assertHasErrors(['newComment' => 'required']);

    $component
        ->set('newComment', '**The first review is complete.** Please check the spacing.')
        ->call('addComment')
        ->assertHasNoErrors()
        ->assertSet('newComment', '')
        ->assertSeeHtml('<strong>The first review is complete.</strong>')
        ->assertSee($user->name);

    $comment = TaskComment::query()->sole();

    expect($comment->task_id)->toBe($task->id)
        ->and($comment->author_id)->toBe($user->id)
        ->and($comment->body)->toBe('**The first review is complete.** Please check the spacing.')
        ->and($comment->renderedBody())->toContain('<strong>The first review is complete.</strong>');
});

it('links each company on the platform dashboard to its administration page', function (): void {
    $company = Company::factory()->create(['name' => 'Linked Maritime']);
    $viewer = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $viewer->id]);

    Livewire\Livewire::test('platform.dashboard')
        ->assertSee('Linked Maritime')
        ->assertSee(route('platform.companies.show', ['company' => $company]), escape: false);
});

it('lists every platform administrator and excludes regular accounts', function (): void {
    $viewer = Account::factory()->platformAdmin()->create([
        'name' => 'Platform Owner',
        'email' => 'owner@platform.example',
    ]);
    Account::factory()->platformAdmin()->create([
        'name' => 'Platform Operator',
        'email' => 'operator@platform.example',
    ]);
    Account::factory()->create([
        'name' => 'Regular Account',
        'email' => 'regular@example.com',
    ]);

    $this->withSession(['platform_account_id' => $viewer->id]);

    Livewire\Livewire::test('platform.users')
        ->assertSee(__('Platform administrators'))
        ->assertSee('Platform Owner')
        ->assertSee('owner@platform.example')
        ->assertSee('Platform Operator')
        ->assertDontSee('Regular Account');
});

it('invites and authorizes a new platform administrator without an environment allowlist', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    config()->set('oceanix.platform_admin_emails', []);
    $viewer = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $viewer->id]);
    Http::fake([
        'api.workos.com/user_management/invitations' => Http::response(['id' => 'inv_platform'], 201),
    ]);

    Livewire\Livewire::test('platform.users')
        ->set('administratorName', 'New Platform Admin')
        ->set('administratorEmail', 'new-platform@example.com')
        ->call('inviteAdministrator')
        ->assertHasNoErrors()
        ->assertSee('new-platform@example.com');

    $invited = Account::query()->where('email', 'new-platform@example.com')->firstOrFail();
    expect($invited->is_platform_admin)->toBeTrue();

    $authenticated = app(AuthenticatePlatformAccount::class)->handle(new SocialIdentity(
        provider: 'workos',
        providerId: 'user_new_platform',
        email: 'new-platform@example.com',
        name: 'New Platform Admin',
        emailVerified: true,
    ));

    expect($authenticated->id)->toBe($invited->id);
    Http::assertSent(fn ($request): bool => $request['email'] === 'new-platform@example.com'
        && ! isset($request['organization_id']));
});

it('keeps platform users limited to platform administrators', function (): void {
    $viewer = Account::factory()->platformAdmin()->create();
    $companyPerson = User::factory()->create([
        'name' => 'Tenant Only Person',
        'email' => 'tenant-only@example.com',
    ]);
    $this->withSession(['platform_account_id' => $viewer->id]);

    Livewire\Livewire::test('platform.users')
        ->assertSee($viewer->email)
        ->assertDontSee($companyPerson->email);
});

it('denies platform users to a company administrator', function (): void {
    $this->actingAs(adminUser())
        ->get(route('platform.users'))
        ->assertForbidden();
});

it('allows a platform administrator to create a company', function (): void {
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);

    $this->actingAs($user)
        ->get(route('platform.companies'))
        ->assertOk();

    Livewire\Livewire::actingAs($user)
        ->test('platform.companies')
        ->set('name', 'Hydra Maritime')
        ->set('slug', 'hydra-maritime')
        ->call('create')
        ->assertHasNoErrors();

    $company = Company::query()->where('slug', 'hydra-maritime')->firstOrFail();
    app(TenantContext::class)->set($company);

    expect(AuditLog::query()->where('action', 'platform.company_created')->exists())->toBeTrue();
});

it('shows only platform-owned metrics on the global dashboard', function (): void {
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);

    $this->actingAs($user)
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertDontSee(__('Assignments'))
        ->assertDontSee(__('Courses'))
        ->assertDontSee(__('People'));

    $metrics = app(PlatformOverview::class)->metrics();

    expect(array_keys($metrics))->toBe(['companies', 'platform_administrators'])
        ->and($metrics['platform_administrators'])->toBe(1);
});

it('allows a platform administrator to suspend and reactivate a company with an audit trail', function (): void {
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);
    $company = currentCompany();

    Livewire\Livewire::actingAs($user)
        ->test('platform.company', ['company' => $company])
        ->call('changeStatus', 'suspended')
        ->assertHasNoErrors();

    expect($company->fresh()->status)->toBe('suspended');
    app(TenantContext::class)->set($company);
    expect(AuditLog::query()->where('action', 'platform.company_status_changed')->count())->toBe(1);

    Livewire\Livewire::actingAs($user)
        ->test('platform.company', ['company' => $company])
        ->call('changeStatus', 'active')
        ->assertHasNoErrors();

    expect($company->fresh()->status)->toBe('active');
});

it('logs out the tenant guard when leaving the platform', function (): void {
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);

    $this->actingAs($user)
        ->post(route('platform.logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('offers every linked company identity instead of an ambiguous return button', function (): void {
    $firstCompany = currentCompany();
    $firstPerson = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $firstPerson->email]);
    $firstPerson->update(['account_id' => $account->id, 'employee_id' => 'FIRST-CPF']);

    $secondCompany = Company::factory()->create(['name' => 'Second Maritime']);
    app(TenantContext::class)->set($secondCompany);
    User::factory()->create([
        'account_id' => $account->id,
        'employee_id' => 'SECOND-CPF',
        'email' => $firstPerson->email,
    ]);
    app(TenantContext::class)->set($firstCompany);

    $this->actingAs($firstPerson)
        ->withSession(['platform_account_id' => $account->id, 'company_id' => $firstCompany->id])
        ->get(route('platform.users'))
        ->assertOk()
        ->assertSee(__('Choose company'))
        ->assertSee($firstCompany->name)
        ->assertSee('FIRST-CPF')
        ->assertSee('Second Maritime')
        ->assertSee('SECOND-CPF')
        ->assertSee(route('platform.companies.enter', ['company' => $firstCompany]), escape: false)
        ->assertSee(route('platform.companies.enter', ['company' => $secondCompany]), escape: false)
        ->assertDontSee(__('Return to company'));
});

it('redirects an old unscoped tenant URL to its company-scoped equivalent', function (): void {
    $user = adminUser();
    $company = currentCompany();

    $this->actingAs($user)
        ->withSession(['company_id' => $company->id])
        ->get('/dashboard')
        ->assertRedirect(route('dashboard', ['company' => $company]));
});

it('creates the first company and its administrator from a session-only platform account', function (): void {
    Company::query()->delete();
    app(TenantContext::class)->clear();
    $account = Account::factory()->platformAdmin()->create(['email' => 'bootstrap@example.com']);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.companies')
        ->set('name', 'First Company')
        ->set('slug', 'first-company')
        ->call('create')
        ->assertHasNoErrors();

    $company = Company::query()->where('slug', 'first-company')->firstOrFail();
    app(TenantContext::class)->set($company);
    $person = User::query()->where('account_id', $account->id)->firstOrFail();

    expect($person->hasRole('admin'))->toBeTrue();

    app(TenantContext::class)->clear();
    $this->post(route('platform.companies.enter', ['company' => $company]))
        ->assertRedirect(route('dashboard', ['company' => $company]));
    expect(auth()->id())->toBe($person->id);
});

it('lets a platform administrator explicitly create access to an existing company', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_company']);
    $account = Account::factory()->platformAdmin()->create(['email' => 'platform-owner@example.com']);
    $this->withSession(['platform_account_id' => $account->id]);
    Http::fake([
        'api.workos.com/user_management/organization_memberships*' => Http::sequence()
            ->push(['data' => []])
            ->push(['id' => 'om_platform']),
    ]);

    Livewire\Livewire::test('platform.company', ['company' => $company])
        ->call('grantMyAccess')
        ->assertHasNoErrors();

    app(TenantContext::class)->set($company);
    $person = User::query()->where('account_id', $account->id)->firstOrFail();

    expect($person->hasRole('admin'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'platform.company_admin_access_granted')->exists())->toBeTrue();
});

it('creates a membership instead of inviting an existing WorkOS user', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_company']);
    $platformAccount = Account::factory()->platformAdmin()->create();
    Account::factory()->create([
        'email' => 'existing-admin@example.com',
        'provider' => 'workos',
        'provider_id' => 'user_existing',
        'workos_user_id' => 'user_existing',
    ]);
    $this->withSession(['platform_account_id' => $platformAccount->id]);
    Http::fake([
        'api.workos.com/user_management/organization_memberships*' => Http::sequence()
            ->push(['data' => []])
            ->push(['id' => 'om_existing']),
    ]);

    Livewire\Livewire::test('platform.company', ['company' => $company])
        ->set('administratorName', 'Existing Administrator')
        ->set('administratorEmail', 'existing-admin@example.com')
        ->call('inviteAdministrator')
        ->assertHasNoErrors()
        ->assertSee(__('Existing WorkOS user added as a company administrator.'));

    app(TenantContext::class)->set($company);
    $person = User::query()->where('email', 'existing-admin@example.com')->firstOrFail();

    expect($person->hasRole('admin'))->toBeTrue()
        ->and($person->workos_invitation_id)->toBeNull();

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.workos.com/user_management/organization_memberships'
        && $request['user_id'] === 'user_existing'
        && $request['organization_id'] === 'org_company');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/invitations'));
});

it('lets a platform administrator invite another company administrator', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_company']);
    $account = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);
    Http::fake([
        'api.workos.com/user_management/invitations' => Http::response(['id' => 'inv_admin'], 201),
    ]);

    Livewire\Livewire::test('platform.company', ['company' => $company])
        ->set('administratorName', 'Tenant Administrator')
        ->set('administratorEmail', 'tenant-admin@example.com')
        ->call('inviteAdministrator')
        ->assertHasNoErrors();

    app(TenantContext::class)->set($company);
    $person = User::query()->where('email', 'tenant-admin@example.com')->firstOrFail();

    expect($person->hasRole('admin'))->toBeTrue()
        ->and($person->workos_invitation_id)->toBe('inv_admin')
        ->and(AuditLog::query()->where('action', 'platform.company_administrator_granted')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request['email'] === 'tenant-admin@example.com'
        && $request['organization_id'] === 'org_company');
});

it('lists every administrator for the selected company and no one from another company', function (): void {
    $company = currentCompany();
    $first = adminUser();
    $first->update(['name' => 'Alpha Administrator', 'email' => 'alpha-admin@example.com']);
    $second = adminUser();
    $second->update(['name' => 'Beta Administrator', 'email' => 'beta-admin@example.com']);

    $otherCompany = Company::factory()->create();
    app(TenantContext::class)->set($otherCompany);
    $foreign = adminUser();
    $foreign->update(['name' => 'Foreign Administrator', 'email' => 'foreign-admin@example.com']);
    app(TenantContext::class)->set($company);

    $platformAccount = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $platformAccount->id]);

    Livewire\Livewire::test('platform.company', ['company' => $company])
        ->assertSee(__('Company administrators'))
        ->assertSee('Alpha Administrator')
        ->assertSee('alpha-admin@example.com')
        ->assertSee('Beta Administrator')
        ->assertDontSee('Foreign Administrator');
});

it('provisions a company in WorkOS with its stable public id', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);
    $company = currentCompany();

    Http::fake([
        'api.workos.com/organizations/external_id/*' => Http::response([], 404),
        'api.workos.com/organizations' => Http::response([
            'id' => 'org_oceanix_demo',
            'name' => $company->name,
            'external_id' => $company->public_id,
        ], 201),
    ]);

    Livewire\Livewire::actingAs($user)
        ->test('platform.companies')
        ->call('provisionWorkos', $company->id)
        ->assertHasNoErrors();

    expect($company->fresh()->workos_organization_id)->toBe('org_oceanix_demo');

    app(TenantContext::class)->set($company);
    expect(AuditLog::query()->where('action', 'platform.company_workos_provisioned')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.workos.com/organizations'
        && $request['name'] === $company->name
        && $request['external_id'] === $company->public_id
        && $request->hasHeader('Authorization', 'Bearer sk_test'));
});

it('reconnects an existing WorkOS organization by external id', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);
    $company = currentCompany();

    Http::fake([
        'api.workos.com/organizations/external_id/*' => Http::response([
            'id' => 'org_existing',
            'external_id' => $company->public_id,
        ]),
    ]);

    Livewire\Livewire::actingAs($user)
        ->test('platform.companies')
        ->call('provisionWorkos', $company->id)
        ->assertHasNoErrors();

    expect($company->fresh()->workos_organization_id)->toBe('org_existing');
    Http::assertSentCount(1);
});
