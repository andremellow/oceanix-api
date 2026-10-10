<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
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
        if (! in_array($response->status(), [200, 201, 202], true) || ! is_array($response->json())) {
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
