<?php

use App\Models\Company;

it('puts authenticated operational pages below the company slug', function (): void {
    expect(route('dashboard'))->toContain('/c/'.currentCompany()->slug.'/dashboard');
});

it('generates tenant urls without an empty company query parameter', function (): void {
    expect(route('courses.index'))->toBe(url('/c/'.currentCompany()->slug.'/courses'));
});

it('redirects an authenticated root request into its company workspace', function (): void {
    $user = employeeUser();

    $this->actingAs($user)
        ->withSession(['company_id' => currentCompany()->id])
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('turns the bare company URL into the correct tenant entry point', function (): void {
    $company = currentCompany();

    $this->get(route('company.entry', ['company' => $company]))
        ->assertRedirect(route('tenant.login', ['company' => $company]));

    $this->actingAs(employeeUser())
        ->get(route('company.entry', ['company' => $company]))
        ->assertRedirect(route('dashboard', ['company' => $company]));
});

it('does not allow a person from one company into another company route', function (): void {
    $user = employeeUser();
    $other = Company::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['company' => $other]))
        ->assertNotFound();
});
