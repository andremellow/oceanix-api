<?php

namespace App\Services\Workos;

use App\Data\WorkosInvitationSnapshot;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WorkosInvitationService
{
    public function get(string $id): ?array
    {
        $r = $this->readClient()->get('/user_management/invitations/'.rawurlencode($id));
        if ($r->notFound()) {
            return null;
        }if (! $r->successful()) {
            throw new RuntimeException('provider_verification_failed');
        }

        return $r->json();
    }

    public function listPage(string $organization, string $email, ?string $after = null): array
    {
        $r = $this->readClient()->get('/user_management/invitations', array_filter(['organization_id' => $organization, 'email' => $email, 'limit' => 100, 'after' => $after], fn ($v) => $v !== null));
        if (! $r->successful() || ! is_array($r->json('data'))) {
            throw new RuntimeException('provider_verification_failed');
        }

        return $r->json();
    }

    public function user(string $id, string $email): array
    {
        $r = $this->readClient()->get('/user_management/users/'.rawurlencode($id));
        $d = $r->json();
        if (! $r->successful() || ! is_array($d) || ($d['id'] ?? null) !== $id || strtolower(trim($d['email'] ?? '')) !== strtolower(trim($email))) {
            throw new RuntimeException('provider_verification_failed');
        }

        return $d;
    }

    public function resend(User $person): WorkosInvitationSnapshot
    {
        return $this->post('/user_management/invitations/'.rawurlencode($person->workos_invitation_id).'/resend', [], $person);
    }

    public function create(User $person): WorkosInvitationSnapshot
    {
        return $this->post('/user_management/invitations', ['email' => $person->email, 'organization_id' => $person->company->workos_organization_id, 'locale' => app()->getLocale() === 'pt_BR' ? 'pt-BR' : 'en-US'], $person);
    }

    private function post(string $url, array $body, User $person): WorkosInvitationSnapshot
    {
        // Mutating calls are deliberately never retried: delivery may have succeeded remotely.
        $r = $this->client()->post($url, $body);
        if (! $r->successful()) {
            throw new RuntimeException($r->serverError() ? 'delivery_unconfirmed' : 'send_failed');
        }
        try {
            return WorkosInvitationSnapshot::from($r->json(), $person->email, $person->company->workos_organization_id);
        } catch (\Throwable) {
            throw new RuntimeException('delivery_unconfirmed');
        }
    }

    private function readClient(): PendingRequest
    {
        return $this->client()->retry(3, function (int $attempt, \Exception $error): int {
            return $error instanceof RequestException && $error->response->status() === 429
                ? min(2000, max(100, (int) $error->response->header('Retry-After') * 1000)) : 100;
        }, fn (\Exception $error): bool => $error instanceof ConnectionException
            || ($error instanceof RequestException && ($error->response->serverError() || $error->response->status() === 429)), throw: false);
    }

    private function client(): PendingRequest
    {
        $key = (string) config('services.workos.api_key');
        if ($key === '') {
            throw new RuntimeException('provider_not_configured');
        }

        return Http::baseUrl('https://api.workos.com')->withToken($key)->acceptJson()->asJson()->connectTimeout(5)->timeout(12);
    }
}
