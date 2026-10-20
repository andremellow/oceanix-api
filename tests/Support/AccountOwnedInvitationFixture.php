<?php

namespace Tests\Support;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\AccountCompanyBinding;
use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\Role;
use App\Models\ServicePrincipal;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class AccountOwnedInvitationFixture
{
    public static function directory(): string
    {
        $marker = '/private/tmp/account-owned-invitation-run.path';
        $directory = is_file($marker) ? trim(file_get_contents($marker)) : '';
        if (! str_starts_with($directory, '/private/tmp/account-owned-invitation-run-') || ! is_dir($directory)) {
            throw new \RuntimeException('Private invitation fixture marker required.');
        }

        return $directory;
    }

    public static function assertHttpQaDatabase(): void
    {
        $directory = self::directory();
        if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql.database') !== 'account_owned_compliance' || config('database.connections.pgsql.host') !== $directory.'/pgsocket' || (int) config('database.connections.pgsql.port') !== 15587 || DB::selectOne('select current_database() as name')->name !== 'account_owned_compliance') {
            throw new \RuntimeException('Exact disposable invitation database required.');
        }
    }

    public static function snapshotHttpQa(): array
    {
        self::assertHttpQaDatabase();
        $data = [];
        foreach (['companies', 'users', 'person_account_links', 'person_product_accesses', 'account_request_operations', 'product_operation_receipts', 'workos_sync_runs', 'workos_invitation_attempts'] as $table) {
            $data[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return $data;
    }

    public static function bootHttpQa(): void
    {
        self::assertHttpQaDatabase();
        $connections = json_decode(file_get_contents(self::directory().'/http-connections.json'), true, flags: JSON_THROW_ON_ERROR);
        config(['services.account.invitation_ownership_enabled' => true, 'services.workos.api_key' => 'synthetic-http-qa-only']);
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:16586/*']);
        config(['services.account.url' => 'http://127.0.0.1:16586', 'services.account.token' => $connections['account_token'], 'services.account.actor_assertion_secret' => 'synthetic-http-qa-actor-proof']);
        Http::fake(['api.workos.com/*' => fn () => throw new \RuntimeException('Compliance operational provider request forbidden.')]);
        Route::middleware('web')->get('/__invitation-qa/platform-login', function (Request $request) {
            abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
            $actor = self::seedPlatformHttpQa();
            Auth::logout();
            $request->session()->put('platform_account_id', $actor['id']);
            $request->session()->regenerate();

            return redirect('/platform/companies');
        });
        Route::get('/__invitation-qa/state', function (Request $request) {
            abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

            return self::snapshotHttpQa();
        });
        Route::middleware('web')->get('/__invitation-qa/login/{company}/{actor}', function (Request $request, string $company, string $actor) {
            abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true) && str_starts_with($actor, 'user_qa_'), 403);
            $record = Company::where('slug', $company)->firstOrFail();
            $user = User::withoutGlobalScope('company')->where('company_id', $record->id)->where('workos_user_id', $actor)->firstOrFail();
            Auth::login($user);
            $request->session()->regenerate();

            return redirect('/c/'.$company.'/people');
        });
    }

    public static function seedPlatformHttpQa(): array
    {
        self::assertHttpQaDatabase();
        $account = Account::firstOrCreate(['workos_user_id' => 'user_qa_platform'], ['name' => 'Synthetic Platform Operator', 'email' => 'platform@invitation.example.test', 'provider' => 'workos', 'provider_id' => 'user_qa_platform', 'status' => 'active', 'is_platform_admin' => true]);

        return ['id' => $account->id, 'workos_user_id' => $account->workos_user_id];
    }

    public static function seedHttpQa(array $account): array
    {
        self::assertHttpQaDatabase();
        $data = ['companies' => [], 'people' => []];
        foreach ($account['companies'] as $key => $values) {
            $company = Company::create(['name' => $values['name'], 'slug' => 'qa-'.$key, 'workos_organization_id' => $values['organization_id'], 'status' => 'active', 'compliance_access_enabled' => true, 'compliance_access_version' => 1]);
            app(TenantContext::class)->set($company);
            (new PermissionSeeder)->run();
            (new RoleSeeder)->run();
            AccountCompanyBinding::create(['account_company_uuid' => $values['uuid'], 'compliance_company_id' => $company->id, 'workos_organization_id' => $values['organization_id']]);
            $data['companies'][$key] = ['public_id' => $company->public_id, 'slug' => $company->slug];
            foreach ($account['people'][$key] as $role => $valuesPerson) {
                $identity = Account::firstOrCreate(['workos_user_id' => $valuesPerson['workos_user_id']], ['name' => $valuesPerson['name'], 'email' => $valuesPerson['email'], 'provider' => 'workos', 'provider_id' => $valuesPerson['workos_user_id'], 'status' => 'active']);
                $user = User::create(['name' => $valuesPerson['name'], 'email' => $valuesPerson['email'], 'workos_user_id' => $valuesPerson['workos_user_id'], 'account_id' => $identity->id, 'status' => UserStatus::Active, 'workos_active_membership' => true, 'first_access_at' => $role === 'target' ? now()->subDays(10) : null]);
                $profile = Role::where('key', $role === 'manager' ? 'admin' : 'employee')->sole();
                $user->roles()->attach($profile);
                if ($role === 'viewer') {
                    $viewer = Role::create(['key' => 'qa-viewer', 'name' => 'QA people viewer']);
                    $keys = Permission::withPrerequisites([Permission::PeopleView]);
                    $viewer->permissions()->sync(\App\Models\Permission::whereIn('key', $keys)->pluck('id'));
                    $user->roles()->attach($viewer);
                }
                PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $user->id, 'account_company_uuid' => $values['uuid'], 'shared_person_id' => $valuesPerson['shared_person_id'], 'company_member_id' => $valuesPerson['member_id'], 'provenance' => ['synthetic_http_qa' => true]]);
                PersonProductAccess::create(['company_id' => $company->id, 'user_id' => $user->id, 'confirmed_enabled' => true, 'version' => 1, 'operation_id' => (string) Str::uuid()]);
                $data['people'][$key][$role] = $user->id;
            }
        }
        $principal = ServicePrincipal::create(['name' => 'Synthetic Account HTTP QA', 'product' => 'compliance', 'environment' => 'testing', 'active' => true, 'capabilities' => ['company.provision', 'company.access', 'person.provision', 'person.access', 'operations.read', 'actors.authorize'], 'company_uuids' => []]);
        $data['compliance_token'] = $principal->createToken('synthetic-http-qa', ['operations.submit', ...$principal->capabilities])->plainTextToken;
        app(TenantContext::class)->clear();

        return $data;
    }
}
