<?php

namespace App\Services\Account;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    public function submit(User $actor, array $envelope): array
    {
        $envelope['actor_assertion'] = app(AccountActorAssertion::class)->sign($actor, $envelope);
        $response = $this->client()->withHeaders(['Idempotency-Key' => $envelope['operation_id']])->put('/api/product-access/v1/operations/'.$envelope['operation_id'], $envelope);
        if (! in_array($response->status(), ($envelope['payload']['registration_mode'] ?? null) === 'confirmed-first' ? [200] : [200, 201, 202], true) || ! is_array($response->json())) {
            throw new RuntimeException('Account confirmation is unavailable.', $response->status());
        }

        return $this->validateReceipt($envelope, $response->json());
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
