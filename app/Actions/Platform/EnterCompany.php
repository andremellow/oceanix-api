<?php

namespace App\Actions\Platform;

use App\Actions\Auth\RecordTenantAccess;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformAccess;
use App\Services\Tenancy\CompanyComplianceAccess;
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
        app(CompanyComplianceAccess::class)->assertEnabled($company);
        abort_unless($company->fresh()->status === 'active', 403);
        $person = User::withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('account_id', $account->id)
            ->firstOrFail();

        abort_unless($person->status->canAccessTenant(), 403);

        $previous = $this->tenant->get();
        $this->tenant->set($company);
        session(['company_id' => $company->id]);
        Auth::login($person, remember: true);
        session()->regenerate();
        try {
            $person = app(RecordTenantAccess::class)->handle($person);
        } catch (\Throwable $exception) {
            Auth::logout();
            session()->forget('company_id');
            $previous === null ? $this->tenant->clear() : $this->tenant->set($previous);
            throw $exception;
        }
        $this->audit->log('platform.company_entered', $company, metadata: ['account_id' => $account->id]);

        return $person;
    }
}
