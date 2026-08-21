<?php

namespace App\Actions\Platform;

use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformAccess;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

class EnterCompany
{
    public function __construct(
        private readonly PlatformAccess $access,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Company $company): User
    {
        $account = $this->access->authorize();
        $person = User::withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('account_id', $account->id)
            ->firstOrFail();

        abort_unless($person->status->isEligibleForTraining(), 403);

        $this->tenant->set($company);
        session(['company_id' => $company->id]);
        Auth::login($person, remember: true);
        session()->regenerate();
        $this->audit->log('platform.company_entered', $company, metadata: ['account_id' => $account->id]);

        return $person;
    }
}
