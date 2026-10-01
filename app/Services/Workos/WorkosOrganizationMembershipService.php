<?php

namespace App\Services\Workos;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WorkosOrganizationMembershipService
{
    public function activeMembership(string $userId, string $organizationId): bool
    {
        $after = null;
        $seen = [];
        do {
            $response = $this->client()->retry(3, 100, throw: false)->get('/user_management/organization_memberships', array_filter([
                'user_id' => $userId, 'organization_id' => $organizationId, 'statuses' => ['active'], 'limit' => 100, 'after' => $after,
            ], fn ($value) => $value !== null));
            if (! $response->successful() || ! is_array($response->json('data'))) {
                throw new RuntimeException('provider_verification_failed');
            }
            foreach ($response->json('data') as $membership) {
                if (($membership['user_id'] ?? null) === $userId && ($membership['organization_id'] ?? null) === $organizationId && ($membership['status'] ?? null) === 'active') {
                    return true;
                }
            }
            $after = $response->json('list_metadata.after');
            if ($after !== null && (! is_string($after) || isset($seen[$after]))) {
                throw new RuntimeException('provider_verification_failed');
            }
            if ($after !== null) {
                $seen[$after] = true;
            }
        } while ($after !== null && $after !== '');

        return false;
    }

    public function ensure(User $person, Company $company): string
    {
        $userId = $person->workos_user_id ?: $person->account?->workos_user_id;
        $organizationId = $company->workos_organization_id;

        if (blank($userId) || blank($organizationId)) {
            throw new RuntimeException(__('The WorkOS user and organization must exist before linking them.'));
        }

        try {
            $existing = $this->client()->get('/user_management/organization_memberships', [
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'statuses' => ['active', 'pending'],
            ]);

            $membershipId = $existing->json('data.0.id');

            if ($existing->successful() && is_string($membershipId) && $membershipId !== '') {
                return $membershipId;
            }

            $response = $this->client()->post('/user_management/organization_memberships', [
                'user_id' => $userId,
                'organization_id' => $organizationId,
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('WorkOS could not be reached. Try again.'), previous: $exception);
        }

        $membershipId = $response->json('id');

        if ($response->failed() || ! is_string($membershipId) || $membershipId === '') {
            throw new RuntimeException(__('WorkOS could not add this user to the organization.'));
        }

        return $membershipId;
    }

    private function client(): PendingRequest
    {
        $apiKey = (string) config('services.workos.api_key');

        if ($apiKey === '') {
            throw new RuntimeException(__('WorkOS is not configured.'));
        }

        return Http::baseUrl('https://api.workos.com')
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)->timeout(12);
    }
}
