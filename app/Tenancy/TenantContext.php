<?php

namespace App\Tenancy;

use App\Models\Company;
use LogicException;

class TenantContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function clear(): void
    {
        $this->company = null;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): int
    {
        return $this->company?->getKey()
            ?? throw new LogicException('No company has been selected for this operation.');
    }
}
