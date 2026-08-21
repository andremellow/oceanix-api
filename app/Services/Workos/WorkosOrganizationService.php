<?php

namespace App\Services\Workos;

use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WorkosOrganizationService
{
    public function provision(Company $company): string
    {
        try {
            if (filled($company->workos_organization_id)) {
                $response = $this->client()->put('/organizations/'.$company->workos_organization_id, [
                    'name' => $company->name,
                    'external_id' => $company->public_id,
                ]);
            } else {
                $existing = $this->client()->get('/organizations/external_id/'.rawurlencode($company->public_id));

                $response = $existing->successful()
                    ? $existing
                    : $this->client()->post('/organizations', [
                        'name' => $company->name,
                        'external_id' => $company->public_id,
                    ]);
            }
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('WorkOS could not be reached. Try again.'), previous: $exception);
        }

        if ($response->failed() || ! is_string($response->json('id')) || $response->json('id') === '') {
            throw new RuntimeException(__('WorkOS could not provision this organization.'));
        }

        return $response->json('id');
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
