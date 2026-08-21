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
            ->timeout(12);
    }
}
