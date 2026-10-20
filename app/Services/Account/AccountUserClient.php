<?php

namespace App\Services\Account;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Models\Account;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class AccountUserClient
{
    private function client(): PendingRequest
    {
        $base = rtrim((string) config('services.account.url'), '/');
        $parts = parse_url($base);
        $host = trim($parts['host'] ?? '', '[]');
        $local = app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (! $parts || ! $host || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (($parts['scheme'] ?? '') !== 'https' && ! ($local && ($parts['scheme'] ?? '') === 'http')) || ! config('services.account.token')) {
            throw new RuntimeException('Account connection is unavailable.', 503);
        }

        return Http::baseUrl($base)->withToken(config('services.account.token'))->acceptJson()->asJson()->connectTimeout(3)->timeout(15)->withoutRedirecting();
    }

    public function authorizeAccountOperation(array $envelope): array
    {
        $hash = ApplyProductOperation::hash($envelope);
        $response = $this->client()->post('/api/product-access/v1/actor-authorizations', $envelope + ['payload_hash' => $hash]);
        $proof = $response->json('actor_assertion');
        if ($response->status() !== 200 || ! is_array($proof)) {
            throw new RuntimeException('Account actor authority is unavailable.', 403);
        }
        $signature = $proof['signature'] ?? '';
        unset($proof['signature']);
        foreach (['operation_id', 'actor_workos_user_id', 'account_company_uuid', 'product', 'kind', 'subject_id'] as $key) {
            abort_unless(($proof[$key] ?? null) === $envelope[$key], 403);
        }
        abort_unless(($proof['payload_hash'] ?? null) === $hash && is_int($proof['issued_at'] ?? null) && is_int($proof['expires_at'] ?? null) && $proof['issued_at'] <= now()->timestamp && $proof['expires_at'] >= now()->timestamp && $proof['expires_at'] > $proof['issued_at'] && $proof['expires_at'] - $proof['issued_at'] <= 60 && is_string($proof['nonce'] ?? null) && strlen($proof['nonce']) >= 16, 403);
        ksort($proof, SORT_STRING);
        $secret = (string) config('services.account.actor_assertion_secret');
        abort_unless(strlen($secret) >= 16 && is_string($signature) && hash_equals(hash_hmac('sha256', json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret), $signature), 403);
        abort_unless(Cache::add('account-actor:'.hash('sha256', $proof['nonce']), $envelope['operation_id'], now()->addSeconds(120)), 403);

        return $proof;
    }

    public function submit(User|Account $actor, array $envelope): array
    {
        if (in_array($envelope['kind'], ['person.map-existing', 'person.invite', 'person.invitation.reconcile', 'person.membership.ensure'], true)) {
            abort_unless(config('services.account.invitation_ownership_enabled', false), 409, 'invitation_cutover_not_ready');
        }
        $envelope['actor_assertion'] = app(AccountActorAssertion::class)->sign($actor, $envelope);
        $response = $this->client()->withHeaders(['Idempotency-Key' => $envelope['operation_id']])->put('/api/product-access/v1/operations/'.$envelope['operation_id'], $envelope);
        if (! in_array($response->status(), ($envelope['payload']['registration_mode'] ?? null) === 'confirmed-first' ? [200] : [200, 201, 202], true) || ! is_array($response->json())) {
            throw new RuntimeException('Account confirmation is unavailable.', $response->status());
        }

        return $this->validateReceipt($envelope, $response->json());
    }

    public function platformOperation(Account $actor, Company $company, User $person, string $kind): array
    {
        abort_unless($actor->fresh()->is_platform_admin && $actor->fresh()->status === 'active' && $actor->workos_user_id && $person->company_id === $company->id, 403);
        $binding = AccountCompanyBinding::where('compliance_company_id', $company->id)->firstOrFail();
        $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'platform:'.$actor->id.':'.$company->id.':'.$person->id.':'.$kind);
        if ($kind === 'person.invite') {
            $pending = AccountRequestOperation::where('company_id', $company->id)->where('user_id', $person->id)->where('actor_workos_user_id', $actor->workos_user_id)->where('kind', $kind)->where('state', '!=', 'confirmed')->latest('created_at')->first();
            $id = $pending?->id ?? (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'platform-invite:'.$actor->id.':'.$company->id.':'.$person->id.':'.$person->invitation_generation);
        }
        $link = PersonAccountLink::where('company_id', $company->id)->where('user_id', $person->id)->first();
        abort_if($kind === 'person.invite' && ! $link, 409);
        $payload = ['protocol_version' => 2, 'operation_id' => $id, 'kind' => $kind, 'product' => 'compliance', 'account_company_uuid' => $binding->account_company_uuid, 'workos_organization_id' => $binding->workos_organization_id, 'subject_id' => $kind === 'person.invite' ? $link->company_member_id : (string) $person->id, 'resource_sequence' => max(1, $person->invitation_generation + 1), 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => ['actor_context' => 'platform-account', 'platform_account_id' => $actor->id, 'local_person_id' => $person->id, 'workos_user_id' => $person->workos_user_id, 'invitation_id' => $person->workos_invitation_id, 'generation' => $person->invitation_generation, 'name' => $person->name, 'email' => $person->email]];
        $operation = AccountRequestOperation::firstOrCreate(['id' => $id], ['company_id' => $company->id, 'user_id' => $person->id, 'actor_id' => null, 'kind' => $kind, 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => $payload, 'payload_hash' => ApplyProductOperation::hash($payload)]);
        $receipt = $operation->dispatched_at ? $this->receipt($operation->payload) : null;
        if (! $receipt) {
            abort_unless($actor->fresh()->is_platform_admin && $actor->fresh()->status === 'active', 403);
            $operation->update(['dispatched_at' => now()]);
            $receipt = $this->submit($actor, $operation->payload);
        }
        if ($kind === 'person.invite') {
            $operation->update(['state' => $receipt['outcome'] === 'confirmed' ? 'confirmed' : 'pending', 'receipt' => $receipt]);

            return $receipt['confirmed_state'] + ['outcome' => $receipt['outcome']];
        }
        abort_unless($receipt['outcome'] === 'confirmed', 409, 'Account confirmation is pending.');
        $state = $receipt['confirmed_state'];
        abort_unless(($state['local_person_id'] ?? null) === (string) $person->id && Str::isUuid($state['shared_person_id'] ?? '') && Str::isUuid($state['company_member_id'] ?? '') && is_string($state['workos_user_id'] ?? null) && preg_match('/^user_[A-Za-z0-9_]+$/D', $state['workos_user_id']) && is_string($state['workos_membership_id'] ?? null) && preg_match('/^om_[A-Za-z0-9_]+$/D', $state['workos_membership_id']) && in_array($state['membership_status'] ?? null, ['active', 'pending'], true), 409, 'Account membership confirmation is incomplete.');
        DB::transaction(function () use ($company, $person, $state, $binding, $operation, $receipt) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $locked = User::whereKey($person->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            abort_unless($binding->fresh()->account_company_uuid === $operation->payload['account_company_uuid'], 409);
            abort_unless(! $locked->workos_user_id || $locked->workos_user_id === $state['workos_user_id'], 409);
            $link = PersonAccountLink::firstOrCreate(['company_id' => $company->id, 'user_id' => $person->id], ['account_company_uuid' => $binding->account_company_uuid, 'shared_person_id' => $state['shared_person_id'], 'company_member_id' => $state['company_member_id'], 'provenance' => ['operation_id' => $operation->id, 'actor_context' => 'platform-account']]);
            abort_unless($link->shared_person_id === $state['shared_person_id'] && $link->company_member_id === $state['company_member_id'], 409);
            if (! $locked->workos_user_id) {
                $locked->forceFill(['workos_user_id' => $state['workos_user_id']])->save();
            }
            $operation->update(['state' => 'confirmed', 'receipt' => $receipt]);
        });

        return $state;
    }

    public function receipt(array $envelope): ?array
    {
        $response = $this->client()->get('/api/product-access/v1/operations/'.$envelope['operation_id']);
        if ($response->status() === 404) {
            return null;
        }
        if ($response->status() !== 200 || ! is_array($response->json())) {
            throw new RuntimeException('Account receipt is unavailable.', $response->status());
        }

        return $this->validateReceipt($envelope, $response->json());
    }

    public function finalizeRegistration(User $actor, array $envelope): array
    {
        $envelope['actor_assertion'] = app(AccountActorAssertion::class)->sign($actor, $envelope);
        $response = $this->client()->withHeaders(['Idempotency-Key' => $envelope['operation_id']])->put('/api/product-access/v1/operations/'.$envelope['operation_id'].'/local-person', $envelope);
        if ($response->status() !== 200 || ! is_array($response->json())) {
            throw new RuntimeException('Account mapping is unavailable.', 503);
        }

        return $this->validateMappingReceipt($envelope, $response->json());
    }

    public function validateMappingReceipt(array $envelope, array $receipt): array
    {
        $this->validateReceipt($envelope, $receipt);
        if ($receipt['outcome'] !== 'confirmed' || ($receipt['confirmed_state']['mapping_complete'] ?? null) !== true || ($receipt['actor_workos_user_id'] ?? null) !== $envelope['actor_workos_user_id']) {
            throw new RuntimeException('Account mapping is incomplete.', 409);
        }
        foreach ($envelope['payload'] as $key => $value) {
            if (($receipt['confirmed_state'][$key] ?? null) !== $value) {
                throw new RuntimeException('Account mapping identity does not match.', 409);
            }
        }

        return $receipt;
    }

    public function validateRegistrationReceipt(array $envelope, array $receipt): array
    {
        $this->validateReceipt($envelope, $receipt);
        $state = $receipt['confirmed_state'];
        foreach (['registration_mode' => 'confirmed-first', 'registration_source' => $envelope['payload']['registration_source'], 'actor_workos_user_id' => $envelope['actor_workos_user_id'], 'request_payload_hash' => ApplyProductOperation::hash($envelope)] as $key => $value) {
            if (($state[$key] ?? null) !== $value) {
                throw new RuntimeException('Account registration identity does not match.', 409);
            }
        }
        if ($receipt['outcome'] !== 'confirmed' || ! Str::isUuid($state['shared_person_id'] ?? '') || ! Str::isUuid($state['company_member_id'] ?? '')
            || ! is_string($state['workos_user_id'] ?? null) || ! preg_match('/^user_[A-Za-z0-9_]+$/D', $state['workos_user_id'])
            || ! is_string($state['workos_membership_id'] ?? null) || ! preg_match('/^om_[A-Za-z0-9_]+$/D', $state['workos_membership_id'])
            || ! in_array($state['membership_status'] ?? null, ['active', 'pending'], true) || ! is_bool($state['mapping_complete'] ?? null)) {
            throw new RuntimeException('Account registration confirmation is incomplete.', 409);
        }

        return $receipt;
    }

    private function validateReceipt(array $envelope, array $receipt): array
    {
        foreach (['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'] as $key) {
            if (! array_key_exists($key, $receipt) || $receipt[$key] !== $envelope[$key]) {
                throw new RuntimeException('Account acknowledgement does not match the operation.', 409);
            }
        }
        if (! in_array($receipt['outcome'] ?? null, ['confirmed', 'pending', 'unknown', 'rejected', 'conflict', 'needs_attention'], true) || ! is_array($receipt['confirmed_state'] ?? null)) {
            throw new RuntimeException('Account acknowledgement is incomplete.', 503);
        }

        return $receipt;
    }
}
