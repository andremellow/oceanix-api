<?php

namespace App\Services\Workos;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WorkosInvitationService
{
    public function send(User $person): string
    {
        $organizationId = $person->company->workos_organization_id;

        if (! is_string($organizationId) || $organizationId === '') {
            throw new RuntimeException(__('Provision this company in WorkOS before sending invitations.'));
        }

        try {
            $response = filled($person->workos_invitation_id)
                ? $this->client()->post('/user_management/invitations/'.$person->workos_invitation_id.'/resend')
                : $this->create($person, $organizationId);

            if ($response->notFound() && filled($person->workos_invitation_id)) {
                $response = $this->create($person, $organizationId);
            }
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('WorkOS could not be reached. Try again.'), previous: $exception);
        }

        $invitationId = $response->json('id');

        if ($response->failed() || ! is_string($invitationId) || $invitationId === '') {
            throw new RuntimeException(__('WorkOS could not send this invitation.'));
        }

        return $invitationId;
    }

    private function create(User $person, string $organizationId)
    {
        return $this->client()->post('/user_management/invitations', [
            'email' => $person->email,
            'organization_id' => $organizationId,
            'locale' => app()->getLocale() === 'pt_BR' ? 'pt-BR' : 'en-US',
        ]);
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
