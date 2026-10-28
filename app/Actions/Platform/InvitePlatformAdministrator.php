<?php

namespace App\Actions\Platform;

use Oceanix\Platform\Auth\Contracts\AuthenticationContext;
use Oceanix\Platform\Services\Platform\PlatformAccess;

class InvitePlatformAdministrator extends \Oceanix\Platform\Actions\Platform\InvitePlatformAdministrator
{
    public function __construct(PlatformAccess $access, AuthenticationContext $authentication)
    {
        // Keep the existing consumer credentials authoritative for delegated registration.
        config(['oceanix-platform.auth.workos.api_key' => $authentication->apiKey()]);

        parent::__construct($access);
    }
}
