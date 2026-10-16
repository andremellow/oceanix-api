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
use Illuminate\Support\Facades\Validator;

class ApplyProductOperation
{
    public function __construct(private readonly TenantContext $context) {}

    public static function hash(array $input): string
    {
        unset($input['actor_assertion']);
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', json_encode($sort($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function handle(ServicePrincipal $principal, array $input): ProductOperationReceipt
    {
        $hash = self::hash($input);

        return DB::transaction(function () use ($principal, $input, $hash) {
            $principal = ServicePrincipal::whereKey($principal->id)->lockForUpdate()->firstOrFail();
            abort_unless($principal->active && $principal->environment === app()->environment() && $principal->product === $input['product']
                && (! $principal->expires_at || $principal->expires_at->isFuture())
                && in_array($input['account_company_uuid'], $principal->company_uuids ?? [], true), 403);
            if ($input['kind'] === 'company.provision') {
                $prior = ProductOperationReceipt::where('service_principal_id', $principal->id)->where('operation_id', $input['operation_id'])->first();
                if ($prior) {
                    abort_unless(hash_equals($prior->payload_hash, $hash), 409);

                    return $prior;
                }
                $legacy = app(EnsureCompany::class)->handle($principal, [
                    'account_company_uuid' => $input['account_company_uuid'], 'workos_organization_id' => $input['workos_organization_id'],
                    'name' => $input['payload']['name'], 'slug' => $input['payload']['slug'], 'actor_workos_user_id' => $input['actor_workos_user_id'],
                    'operation_id' => $input['operation_id'], 'correlation_id' => $input['operation_id'],
                ]);
                $binding = AccountCompanyBinding::where('account_company_uuid', $input['account_company_uuid'])->firstOrFail();
                $company = Company::whereKey($binding->compliance_company_id)->lockForUpdate()->firstOrFail();
                abort_unless($company->compliance_access_version === 0 && $input['resource_sequence'] === 1, 409);
                $company->update(['compliance_access_enabled' => $input['payload']['enabled'], 'compliance_access_version' => 1]);
                $response = array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence']))
                    + ['outcome' => 'confirmed', 'confirmed_state' => ['enabled' => true, 'local_company_id' => $legacy->response['compliance_company_public_id']]];

                return ProductOperationReceipt::create(['company_id' => $binding->compliance_company_id, 'service_principal_id' => $principal->id, 'operation_id' => $input['operation_id'], 'subject_id' => $input['subject_id'], 'resource_sequence' => $input['resource_sequence'], 'payload_hash' => $hash, 'request' => $input, 'response' => $response]);
            }
            $binding = AccountCompanyBinding::where('account_company_uuid', $input['account_company_uuid'])->first();
            abort_unless($binding && $binding->workos_organization_id === $input['workos_organization_id'], 409);
            $company = Company::whereKey($binding->compliance_company_id)->lockForUpdate()->firstOrFail();
            abort_unless($company->workos_organization_id === $input['workos_organization_id'], 409);
            $previousReceipt = ProductOperationReceipt::where('service_principal_id', $principal->id)->where('operation_id', $input['operation_id'])->first();
            if ($previousReceipt) {
                abort_unless($previousReceipt->company_id === $company->id && hash_equals($previousReceipt->payload_hash, $hash), 409);

                return $previousReceipt;
            }
            $previousContext = $this->context->get();
            try {
                $this->context->set($company);
                $payload = $input['payload'];
                if ($input['kind'] === 'company.access') {
                    abort_unless($input['subject_id'] === $company->public_id && $input['resource_sequence'] === $company->compliance_access_version + 1, 409);
                    $company->update(['compliance_access_enabled' => $payload['enabled'], 'compliance_access_version' => $input['resource_sequence']]);
                    $state = ['enabled' => (bool) $company->compliance_access_enabled];
                } else {
                    abort_unless(in_array($input['kind'], ['person.provision', 'person.access'], true), 422);
                    $link = PersonAccountLink::where('company_id', $company->id)->where('account_company_uuid', $input['account_company_uuid'])->where('company_member_id', $input['subject_id'])->first();
                    if (! empty($payload['local_person_id'])) {
                        $person = User::whereKey($payload['local_person_id'])->lockForUpdate()->first();
                    } elseif ($link) {
                        $person = User::whereKey($link->user_id)->lockForUpdate()->first();
                    } else {
                        abort_unless($input['kind'] === 'person.provision', 409);
                        Validator::make($payload, ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']])->validate();
                        // Email collisions require explicit adoption; never merge operational evidence.
                        abort_if(User::whereRaw('LOWER(email) = ?', [strtolower(trim($payload['email']))])->exists(), 409);
                        $person = User::create(['name' => trim($payload['name']), 'email' => strtolower(trim($payload['email'])), 'status' => UserStatus::Invited]);
                        $role = Role::where('key', 'employee')->first();
                        if ($role) {
                            $person->roles()->syncWithoutDetaching($role->id);
                        }
                    }
                    abort_unless($person && $person->company_id === $company->id && (! $link || $link->user_id === $person->id), 409);
                    $link ??= PersonAccountLink::where('company_id', $company->id)->where('user_id', $person->id)->first();
                    if ($link) {
                        abort_unless($link->account_company_uuid === $input['account_company_uuid'] && $link->company_member_id === $input['subject_id'] && $link->shared_person_id === $payload['shared_person_id'], 409);
                    } else {
                        abort_unless($input['kind'] === 'person.provision', 409);
                        $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $payload['shared_person_id'], 'company_member_id' => $input['subject_id'], 'provenance' => ['operation_id' => $input['operation_id']]]);
                    }
                    // Stable provider identity may be linked once; local status/roles and evidence stay independent.
                    if (isset($payload['workos_user_id'])) {
                        abort_unless(! $person->workos_user_id || $person->workos_user_id === $payload['workos_user_id'], 409);
                        if (! $person->workos_user_id) {
                            $person->forceFill(['workos_user_id' => $payload['workos_user_id']])->save();
                        }
                    }
                    $access = PersonProductAccess::where('company_id', $company->id)->where('user_id', $person->id)->lockForUpdate()->first();
                    abort_unless($input['resource_sequence'] === ($access?->version ?? 0) + 1, 409);
                    PersonProductAccess::updateOrCreate(['company_id' => $company->id, 'user_id' => $person->id], ['confirmed_enabled' => $payload['enabled'], 'version' => $input['resource_sequence'], 'operation_id' => $input['operation_id']]);
                    $state = ['enabled' => $payload['enabled'], 'local_person_id' => (string) $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id];
                }
                $response = array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => $state];

                return ProductOperationReceipt::create(['company_id' => $company->id, 'service_principal_id' => $principal->id, 'operation_id' => $input['operation_id'], 'subject_id' => $input['subject_id'], 'resource_sequence' => $input['resource_sequence'], 'payload_hash' => $hash, 'request' => $input, 'response' => $response]);
            } finally {
                $previousContext ? $this->context->set($previousContext) : $this->context->clear();
            }
        });
    }

    public function receipt(ServicePrincipal $principal, string $operationId): ProductOperationReceipt
    {
        $receipt = ProductOperationReceipt::where('service_principal_id', $principal->id)->where('operation_id', $operationId)->firstOrFail();
        abort_unless(in_array($receipt->response['account_company_uuid'], $principal->company_uuids ?? [], true), 403);

        return $receipt;
    }
}
