<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

it('links one global account to distinct people in different companies', function (): void {
    $account = Account::factory()->create(['email' => 'andre@example.com']);
    $firstCompany = currentCompany();
    $first = User::factory()->create(['account_id' => $account->id, 'email' => 'andre@example.com']);

    $secondCompany = Company::factory()->create();
    app(TenantContext::class)->set($secondCompany);
    $second = User::factory()->create(['account_id' => $account->id, 'email' => 'andre@example.com']);

    expect($first->id)->not->toBe($second->id)
        ->and($first->company_id)->toBe($firstCompany->id)
        ->and($second->company_id)->toBe($secondCompany->id)
        ->and($account->people()->withoutGlobalScope('company')->count())->toBe(2);
});

it('switches to another linked company and records the context change', function (): void {
    $account = Account::factory()->create();
    $first = User::factory()->create(['account_id' => $account->id, 'email' => $account->email]);
    $secondCompany = Company::factory()->create();
    $second = User::factory()->create(['account_id' => $account->id, 'email' => $account->email]);
    app(TenantContext::class)->set($first->company);

    $this->actingAs($first)
        ->withSession(['company_id' => $first->company_id])
        ->post(route('company.switch', ['targetCompany' => $secondCompany]))
        ->assertRedirect(route('dashboard', ['company' => $secondCompany]));

    expect(auth()->id())->toBe($second->id)
        ->and(AuditLog::withoutGlobalScope('company')->where('action', 'company.context_switched')->exists())->toBeTrue();
});

it('allows the same email once per company', function (): void {
    User::factory()->create(['email' => 'person@example.com']);

    expect(fn () => User::factory()->create(['email' => 'person@example.com']))->toThrow(QueryException::class);

    Company::factory()->create();
    expect(User::factory()->create(['email' => 'person@example.com']))->toBeInstanceOf(User::class);
});
