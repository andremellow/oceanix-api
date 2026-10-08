<?php

namespace App\Actions\Tenancy;

use App\Actions\Auth\RecordTenantAccess;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\CompanyComplianceAccess;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

class SwitchCompany
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $current, Company $company): User
    {
        abort_if($current->account_id === null, 403);
        app(CompanyComplianceAccess::class)->assertEnabled($company);
        abort_unless($company->fresh()->status === 'active', 403);

        $target = User::withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('account_id', $current->account_id)
            ->firstOrFail();

        abort_unless($target->status->canAccessTenant(), 403);

        $previous = $this->context->get();
        $this->context->set($company);
        session(['company_id' => $company->id]);
        Auth::login($target, remember: true);
        session()->regenerate();
        try {
            $target = app(RecordTenantAccess::class)->handle($target);
        } catch (\Throwable $exception) {
            Auth::logout();
            session()->forget('company_id');
            $previous === null ? $this->context->clear() : $this->context->set($previous);
            throw $exception;
        }

        $this->audit->log('company.context_switched', $company, metadata: [
            'account_id' => $current->account_id,
            'from_company_id' => $current->company_id,
            'to_company_id' => $company->id,
        ]);

        return $target;
    }
}
