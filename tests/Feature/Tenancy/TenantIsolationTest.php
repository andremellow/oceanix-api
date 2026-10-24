<?php

use App\Models\Company;
use App\Models\Course;
use App\Models\Department;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

it('allows the same email and business codes in different companies', function (): void {
    $first = currentCompany();
    User::factory()->create(['email' => 'person@example.com']);
    Department::factory()->create(['code' => 'OPS']);
    Course::factory()->create(['code' => 'SAFE']);

    $second = Company::factory()->create(['slug' => 'second-company']);
    app(TenantContext::class)->set($second);

    $user = User::factory()->create(['email' => 'person@example.com']);
    Department::factory()->create(['code' => 'OPS']);
    Course::factory()->create(['code' => 'SAFE']);

    expect($user->company_id)->toBe($second->id)
        ->and(User::query()->count())->toBe(1)
        ->and(Department::query()->pluck('code')->all())->toBe(['OPS'])
        ->and(Course::query()->pluck('code')->all())->toBe(['SAFE']);

    app(TenantContext::class)->set($first);

    expect(User::query()->count())->toBe(1);
});

it('returns a 404 when a user requests a record owned by another company', function (): void {
    $foreignCourse = Course::factory()->create();

    $second = Company::factory()->create(['slug' => 'isolated-company']);
    app(TenantContext::class)->set($second);
    $user = employeeUser();

    $this->actingAs($user)
        ->withSession(['company_id' => $second->id])
        ->get(route('courses.show', ['course' => $foreignCourse->getKey()]))
        ->assertNotFound();
});

it('selects the company before resolving an account with a shared email', function (): void {
    $first = currentCompany();
    $firstUser = User::factory()->create(['email' => 'shared@example.com']);

    $second = Company::factory()->create(['slug' => 'login-company']);
    app(TenantContext::class)->set($second);
    $secondUser = User::factory()->create(['email' => 'shared@example.com']);

    expect(User::query()->where('email', 'shared@example.com')->sole()->id)->toBe($secondUser->id)
        ->and($secondUser->id)->not->toBe($firstUser->id)
        ->and($first->id)->not->toBe($second->id);
});

it('rejects local company creation with Account guidance and no side effects', function (): void {
    Http::fake();
    adminUser();
    $snapshot = fn (): array => collect(['companies', 'users', 'roles', 'permissions', 'role_user', 'account_company_bindings', 'provisioning_receipts', 'audit_logs'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();

    $this->artisan('oceanix:create-company', ['name' => 'North Sea Operations', '--slug' => 'north-sea-operations'])
        ->expectsOutput('Create or import companies in Account, then enable Compliance there.')
        ->assertFailed();

    expect($snapshot())->toBe($before);
    Http::assertNothingSent();
});

it('restores a tenant-scoped user on framework-owned web routes', function (): void {
    $company = currentCompany();
    $user = User::factory()->create();

    Route::middleware(['web', 'auth'])->get('/_test/framework-web-request', fn () => response()->json([
        'user_id' => Auth::id(),
        'company_id' => app(TenantContext::class)->id(),
    ]));

    app(TenantContext::class)->clear();

    $this->withSession([
        Auth::guard()->getName() => $user->id,
        'company_id' => $company->id,
    ])->get('/_test/framework-web-request')
        ->assertOk()
        ->assertJson([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
});
