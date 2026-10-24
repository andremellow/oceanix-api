<?php

namespace App\Services\Platform;

use App\Tenancy\TenantContext;
use Oceanix\Platform\Auth\Contracts\AuthenticationContext;

class ComplianceAuthenticationContext implements AuthenticationContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function clientId(): string
    {
        return (string) config('services.workos.client_id');
    }

    public function apiKey(): string
    {
        return (string) config('services.workos.api_key');
    }

    public function organizationId(): ?string
    {
        return $this->tenant->get()?->workos_organization_id;
    }

    public function providerDefinitions(): array
    {
        return config('services.social_login.providers', []);
    }
}
