<?php

use App\Models\Company;

it('puts authenticated operational pages below the company slug', function (): void {
    expect(route('dashboard'))->toContain('/c/'.currentCompany()->slug.'/dashboard');
});

it('redirects an authenticated root request into its company workspace', function (): void {
    $user = employeeUser();

    $this->actingAs($user)
        ->withSession(['company_id' => currentCompany()->id])
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('does not allow a person from one company into another company route', function (): void {
    $user = employeeUser();
    $other = Company::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['company' => $other]))
        ->assertNotFound();
});
