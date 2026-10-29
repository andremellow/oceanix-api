<?php

use App\Tenancy\TenantContext;
use Database\Factories\AccountFactory;
use Database\Factories\CompanyFactory;
use Illuminate\Support\Facades\Auth;

it('restores an existing company login as its explicitly authorized platform account', function (bool $tenantEnabled) {
    $company = currentCompany();
    $person = adminUser();
    $account = AccountFactory::new()->platformAdmin()->create();
    $person->update(['account_id' => $account->id]);
    $company->update(['compliance_access_enabled' => $tenantEnabled]);
    $guardKey = Auth::guard()->getName();
    Auth::forgetGuards();
    app(TenantContext::class)->clear();

    $this->withSession([$guardKey => $person->id, 'company_id' => $company->id])
        ->get(route('platform.dashboard'))->assertOk()
        ->assertSessionHas('platform_account_id', $account->id);

    expect(app(TenantContext::class)->get())->toBeNull();
})->with([true, false]);

it('does not grant platform access from a company administrator or an inactive platform identity', function (string $identity) {
    $company = currentCompany();
    $person = adminUser();
    $account = AccountFactory::new()->create(['is_platform_admin' => $identity !== 'ordinary', 'status' => $identity === 'inactive' ? 'inactive' : 'active']);
    $person->update(['account_id' => $account->id]);
    $guardKey = Auth::guard()->getName();
    Auth::forgetGuards();
    app(TenantContext::class)->clear();

    $this->withSession([$guardKey => $person->id, 'company_id' => $company->id])
        ->get(route('platform.dashboard'))->assertForbidden()
        ->assertSessionMissing('platform_account_id');
})->with(['ordinary', 'inactive']);

it('does not restore a platform identity through a different company session', function () {
    $person = adminUser();
    $account = AccountFactory::new()->platformAdmin()->create();
    $person->update(['account_id' => $account->id]);
    $foreign = CompanyFactory::new()->create();
    $guardKey = Auth::guard()->getName();
    Auth::forgetGuards();
    app(TenantContext::class)->clear();

    $this->withSession([$guardKey => $person->id, 'company_id' => $foreign->id])
        ->get(route('platform.dashboard'))->assertRedirect(route('platform.login'))
        ->assertSessionMissing('platform_account_id');
});
