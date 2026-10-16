<?php

namespace App\Actions\ControlPlane;

use App\Enums\UserStatus;
use App\Models\AccountCompanyBinding;
use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\ProductOperationReceipt;
use App\Models\Role;
use App\Models\ServicePrincipal;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class MigrateAccountPerson
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(ServicePrincipal $principal, array $input): array
    {
        $payload = $input;
        unset($payload['payload_hash']);
        $hash = ApplyProductOperation::hash($payload);
        abort_unless(hash_equals($hash, $input['payload_hash']), 409);
        $identity = $input['workos_user_id'] ?? null;
        $invitation = $input['invitation_id'] ?? null;
        abort_unless($input['source_key'] === ($identity ? 'user:'.$identity : 'invitation:'.$invitation), 409);
        abort_if(! $identity && $input['baseline_eligible'], 409);

        return DB::transaction(function () use ($principal, $input, $hash, $identity, $invitation) {
            $principal = ServicePrincipal::whereKey($principal->id)->lockForUpdate()->firstOrFail();
            abort_unless($principal->active && $principal->product === 'compliance' && $principal->environment === app()->environment()
                && (! $principal->expires_at || $principal->expires_at->isFuture())
                && in_array('migration.people.link', $principal->capabilities ?? [], true)
                && in_array($input['account_company_uuid'], $principal->company_uuids ?? [], true), 403);
            $binding = AccountCompanyBinding::where('account_company_uuid', $input['account_company_uuid'])->first();
            abort_unless($binding && $binding->workos_organization_id === $input['workos_organization_id'], 409);
            $company = Company::whereKey($binding->compliance_company_id)->lockForUpdate()->firstOrFail();
            abort_unless($company->workos_organization_id === $input['workos_organization_id'], 409);
            $prior = ProductOperationReceipt::where('service_principal_id', $principal->id)->where('operation_id', $input['operation_id'])->first();
            if ($prior) {
                abort_unless($prior->company_id === $company->id && hash_equals($prior->payload_hash, $hash), 409);

                return $prior->response;
            }
            $previous = $this->context->get();
            try {
                $this->context->set($company);
                $matches = User::query()->where($identity ? 'workos_user_id' : 'workos_invitation_id', $identity ?? $invitation)->lockForUpdate()->get();
                abort_if($matches->count() > 1, 409);
                $person = $matches->first();
                $link = PersonAccountLink::where('company_id', $company->id)->where(function ($query) use ($input) {
                    $query->where('company_member_id', $input['company_member_id'])->orWhere('shared_person_id', $input['shared_person_id']);
                })->first();
                if ($link) {
                    abort_unless($person && $link->user_id === $person->id && $link->account_company_uuid === $input['account_company_uuid'] && $link->company_member_id === $input['company_member_id'] && $link->shared_person_id === $input['shared_person_id'], 409);
                }
                if ($person) {
                    abort_unless(strtolower(trim($person->email)) === strtolower(trim($input['email'])), 409);
                    $existing = PersonAccountLink::where('company_id', $company->id)->where('user_id', $person->id)->first();
                    if ($existing) {
                        abort_unless($existing->account_company_uuid === $input['account_company_uuid'] && $existing->company_member_id === $input['company_member_id'] && $existing->shared_person_id === $input['shared_person_id'], 409);
                        $link = $existing;
                    }
                } else {
                    abort_if(User::whereRaw('LOWER(email) = ?', [strtolower(trim($input['email']))])->exists(), 409);
                }
                $access = $person ? PersonProductAccess::where('company_id', $company->id)->where('user_id', $person->id)->lockForUpdate()->first() : null;
                $enabled = (bool) ($person && $person->status === UserStatus::Active && $company->compliance_access_enabled && ($access?->confirmed_enabled ?? $input['baseline_eligible']));
                $response = array_intersect_key($input, array_flip(['operation_id', 'payload_hash', 'account_company_uuid', 'workos_organization_id', 'shared_person_id', 'company_member_id', 'source_key', 'workos_user_id', 'invitation_id']))
                    + ['kind' => 'migration.people.link', 'outcome' => $input['preview'] ? 'ready' : ($link ? 'already_linked' : 'confirmed'), 'local_person_id' => $person ? (string) $person->id : null, 'observed_enabled' => $enabled, 'local_status' => ($person?->status ?? UserStatus::Invited)->value];
                if ($input['preview']) {
                    return $response;
                }
                if (! $person) {
                    $person = User::create(['name' => trim($input['name']), 'email' => strtolower(trim($input['email'])), 'status' => UserStatus::Invited, 'workos_user_id' => $identity, 'workos_invitation_id' => $invitation]);
                    $role = Role::where('key', 'employee')->first();
                    if ($role) {
                        $person->roles()->attach($role->id);
                    }
                    $response['local_person_id'] = (string) $person->id;
                }
                $link ??= PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $input['shared_person_id'], 'company_member_id' => $input['company_member_id'], 'provenance' => ['operation_id' => $input['operation_id'], 'source_key' => $input['source_key'], 'kind' => 'migration.people.link']]);
                if (! $access) {
                    PersonProductAccess::create(['company_id' => $company->id, 'user_id' => $person->id, 'confirmed_enabled' => $enabled, 'version' => 1, 'operation_id' => $input['operation_id']]);
                }
                ProductOperationReceipt::create(['company_id' => $company->id, 'service_principal_id' => $principal->id, 'operation_id' => $input['operation_id'], 'subject_id' => $input['company_member_id'], 'resource_sequence' => 1, 'payload_hash' => $hash, 'request' => $input + ['kind' => 'migration.people.link'], 'response' => $response]);

                return $response;
            } finally {
                $previous ? $this->context->set($previous) : $this->context->clear();
            }
        });
    }
}
