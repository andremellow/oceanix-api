<?php

namespace Tests\Support;

use App\Actions\ControlPlane\ApplyInvitationObservation;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
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
use Oceanix\Platform\Models\Account;
use Oceanix\Platform\Models\Company;
use Ramsey\Uuid\Uuid;

final class AccountIntegrationFixture
{
    public static function fakeAccountConfirmation(): void
    {
        Http::preventStrayRequests();
        config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic-token', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
        Http::fake(['account.example.test/*' => function ($request) {
            $input = $request->data();
            if ($input['kind'] === 'person.register') {
                PersonProductAccess::where('user_id', $input['payload']['local_person_id'])->update(['confirmed_enabled' => true, 'version' => 1, 'operation_id' => (string) Str::uuid()]);
            }

            return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence']))
                + ['outcome' => 'confirmed', 'confirmed_state' => ['shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid()]]);
        }]);
    }

    public static function fakeAccountActorAuthorization(): void
    {
        config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic-token', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
        Http::fake(['account.example.test/api/product-access/v1/actor-authorizations' => function ($request) {
            $input = $request->data();
            $proof = array_intersect_key($input, array_flip(['operation_id', 'actor_workos_user_id', 'account_company_uuid', 'product', 'kind', 'subject_id'])) + ['payload_hash' => $input['payload_hash'], 'issued_at' => now()->timestamp, 'expires_at' => now()->addSeconds(60)->timestamp, 'nonce' => (string) Str::uuid()];
            ksort($proof, SORT_STRING);
            $proof['signature'] = hash_hmac('sha256', json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'synthetic-actor-proof');

            return Http::response(['actor_assertion' => $proof]);
        }]);
    }

    public static function receiver(): array
    {
        Http::preventStrayRequests();
        $company = app(TenantContext::class)->get();
        $company->update(['workos_organization_id' => 'org_synthetic']);
        $uuid = (string) Str::uuid();
        AccountCompanyBinding::create(['account_company_uuid' => $uuid, 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
        $principal = ServicePrincipal::create(['name' => 'Synthetic Account principal', 'product' => 'compliance', 'environment' => 'testing', 'active' => true, 'capabilities' => ['company.provision', 'company.access', 'person.provision', 'person.access', 'operations.read', 'actors.authorize'], 'company_uuids' => [$uuid]]);
        $token = $principal->createToken('synthetic-account', ['operations.submit', ...$principal->capabilities])->plainTextToken;
        $person = User::factory()->create(['workos_user_id' => 'user_synthetic_target']);
        $envelope = ['protocol_version' => 2, 'operation_id' => (string) Str::uuid(), 'kind' => 'person.provision', 'product' => 'compliance', 'account_company_uuid' => $uuid, 'workos_organization_id' => $company->workos_organization_id, 'subject_id' => (string) Str::uuid(), 'resource_sequence' => 1, 'actor_workos_user_id' => 'user_synthetic_actor', 'payload' => ['local_person_id' => $person->id, 'shared_person_id' => (string) Str::uuid(), 'workos_user_id' => $person->workos_user_id, 'enabled' => true]];

        return [$company, $person, $principal, $token, $envelope];
    }

    public static function invitationLink(User $person): PersonAccountLink
    {
        return PersonAccountLink::firstOrCreate(['company_id' => $person->company_id, 'user_id' => $person->id], ['account_company_uuid' => (string) Str::uuid(), 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'provenance' => ['source' => 'synthetic-adoption']]);
    }

    public static function invitationReceipt(array $envelope, ?array $invitation, bool $sent = true, bool $active = false): array
    {
        return array_intersect_key($envelope, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['sent' => $sent, 'active_membership' => $active, 'generation' => ($envelope['payload']['generation'] ?? 0) + ($sent ? 1 : 0), 'invitation' => $invitation ?? ['id' => null, 'state' => 'none']]];
    }

    public static function fakePlatformConnection(Company $company): void
    {
        AccountCompanyBinding::firstOrCreate(['compliance_company_id' => $company->id], ['account_company_uuid' => (string) Str::uuid(), 'workos_organization_id' => $company->workos_organization_id]);
        config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
        Http::preventStrayRequests();
        $receipts = [];
        Http::fake(['account.example.test/*' => function ($request) use (&$receipts) {
            if ($request->method() === 'GET') {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));

                return isset($receipts[$id]) ? Http::response($receipts[$id]) : Http::response([], 404);
            }
            $input = $request->data();
            $person = User::whereKey($input['payload']['local_person_id'])->firstOrFail();
            $state = ['workos_membership_id' => 'om_platform', 'membership_status' => $input['payload']['workos_user_id'] ? 'active' : 'pending', 'local_person_id' => (string) $person->id, 'shared_person_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'shared:'.$person->id), 'company_member_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'member:'.$person->id), 'workos_user_id' => $input['payload']['workos_user_id'] ?? 'user_platform_generated'];
            if ($input['kind'] === 'person.invite') {
                $membership = AccountRequestOperation::where('user_id', $person->id)->where('kind', 'person.membership.ensure')->sole()->receipt['confirmed_state'];
                $sent = $membership['membership_status'] !== 'active';
                $state = ['sent' => $sent];
                if ($sent) {
                    $snapshot = ['local_person_id' => $person->id, 'shared_person_id' => $membership['shared_person_id'], 'company_member_id' => $membership['company_member_id'], 'workos_user_id' => $person->workos_user_id, 'generation' => $input['payload']['generation'] + 1, 'observation_sequence' => 1, 'verified_at' => now()->toIso8601String(), 'accepted_history_at' => null, 'invitation' => ['id' => 'invitation_admin', 'state' => 'pending', 'created_at' => '2026-10-01T00:00:00Z']];
                    DB::transaction(fn () => app(ApplyInvitationObservation::class)->handle($person->company, ['account_company_uuid' => $input['account_company_uuid'], 'subject_id' => $membership['company_member_id'], 'payload' => $snapshot]));
                }
            }

            $receipts[$input['operation_id']] = array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => $state];

            return Http::response($receipts[$input['operation_id']]);
        }]);
    }

    public static function observationReceipt(array $envelope, User $person, string $state = 'pending', int $sequence = 1): array
    {
        $link = self::invitationLink($person);
        $invitation = $state === 'none' ? ['id' => null, 'state' => 'none'] : ['id' => $person->workos_invitation_id, 'state' => $state, 'created_at' => '2026-10-01T00:00:00Z', 'expires_at' => '2026-10-31T00:00:00Z', 'accepted_at' => $state === 'accepted' ? '2026-10-10T00:00:00Z' : null, 'accepted_user_id' => $person->workos_user_id];
        $snapshot = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => $person->workos_user_id, 'generation' => (int) $person->invitation_generation, 'observation_sequence' => $sequence, 'verified_at' => '2026-10-20T00:00:00Z', 'accepted_history_at' => $state === 'accepted' ? '2026-10-10T00:00:00Z' : null, 'invitation' => $invitation];

        return array_intersect_key($envelope, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['observation' => $snapshot, 'invitation' => $invitation, 'generation' => (int) $person->invitation_generation, 'sent' => false]];
    }

    public static function assertHttpQaDatabase(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql.database') !== 'compliance_company_user_qa' || (int) config('database.connections.pgsql.port') !== 15439 || config('database.connections.pgsql.host') !== '/private/tmp/oceanx-company-user-qa/pgsocket' || DB::selectOne('select current_database() as name')->name !== 'compliance_company_user_qa') {
            throw new \RuntimeException('Synthetic HTTP QA requires the disposable Compliance database.');
        }
    }

    public static function bootHttpQa(): void
    {
        self::assertHttpQaDatabase();
        $connections = json_decode(file_get_contents('/private/tmp/oceanx-company-user-qa/http-connections.json'), true, flags: JSON_THROW_ON_ERROR);
        config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'http://127.0.0.1:18081', 'services.account.token' => $connections['account_token'], 'services.account.actor_assertion_secret' => 'synthetic-http-qa-actor-proof', 'services.workos.api_key' => 'synthetic-http-qa-only']);
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:18081/*']);
        // Product coordination uses Account; any remaining legacy provider read fails closed.
        Http::fake(['api.workos.com/*' => Http::response(['error' => 'Use synthetic Account coordination'], 503)]);
        Route::middleware('web')->get('/__company-user-qa/login/{company}/{actor}', function (Request $request, string $company, string $actor) {
            abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true) && str_starts_with($actor, 'user_qa_'), 403);
            $record = Company::where('slug', $company)->firstOrFail();
            $user = User::withoutGlobalScope('company')->where('company_id', $record->id)->where('workos_user_id', $actor)->firstOrFail();
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('people.index', ['company' => $record->slug]);
        });
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
        $principal = ServicePrincipal::create(['name' => 'Synthetic Account HTTP QA', 'product' => 'compliance', 'environment' => 'testing', 'active' => true, 'capabilities' => ['company.provision', 'company.access', 'person.provision', 'person.access', 'operations.read', 'actors.authorize'], 'company_uuids' => array_column($account['companies'], 'uuid')]);
        $data['compliance_token'] = $principal->createToken('synthetic-http-qa', ['operations.submit', ...$principal->capabilities])->plainTextToken;
        app(TenantContext::class)->clear();

        return $data;
    }
}
