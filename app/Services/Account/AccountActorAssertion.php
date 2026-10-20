<?php

namespace App\Services\Account;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class AccountActorAssertion
{
    public function sign(User|Account $actor, array $envelope): array
    {
        if (! $actor->workos_user_id || $actor->workos_user_id !== $envelope['actor_workos_user_id']) {
            throw new RuntimeException('The initiating actor requires a verified stable identity.', 403);
        }
        $secret = (string) config('services.account.actor_assertion_secret');
        if (strlen($secret) < 16) {
            throw new RuntimeException('Account actor authorization is not configured.', 503);
        }
        $assertion = array_intersect_key($envelope, array_flip(['operation_id', 'actor_workos_user_id', 'account_company_uuid', 'product', 'kind', 'subject_id']))
            + ['payload_hash' => ApplyProductOperation::hash($envelope), 'issued_at' => now()->timestamp, 'expires_at' => now()->addSeconds(60)->timestamp, 'nonce' => (string) Str::uuid()];
        ksort($assertion, SORT_STRING);
        $assertion['signature'] = hash_hmac('sha256', json_encode($assertion, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret);

        return $assertion;
    }
}
