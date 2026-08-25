<?php

namespace App\Actions\Platform;

use App\Models\Account;
use App\Services\Platform\PlatformAccess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InvitePlatformAdministrator
{
    public function __construct(private readonly PlatformAccess $access) {}

    public function handle(string $name, string $email): Account
    {
        $this->access->authorize();
        $email = strtolower(trim($email));
        $apiKey = (string) config('services.workos.api_key');

        if ($apiKey === '') {
            throw new RuntimeException(__('WorkOS is not configured.'));
        }

        try {
            $response = Http::baseUrl('https://api.workos.com')
                ->withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(12)
                ->post('/user_management/invitations', [
                    'email' => $email,
                    'locale' => app()->getLocale() === 'pt_BR' ? 'pt-BR' : 'en-US',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('WorkOS could not be reached. Try again.'), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(__('WorkOS could not send this invitation.'));
        }

        return Account::query()->updateOrCreate(
            ['email' => $email],
            ['name' => trim($name), 'is_platform_admin' => true, 'status' => 'active'],
        );
    }
}
