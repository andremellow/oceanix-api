<?php

use App\Models\Certificate;
use App\Models\User;
use App\Tenancy\TenantContext;

it('verifies a certificate without exposing internal identifiers', function (): void {
    $holder = User::factory()->create([
        'name' => 'Helena Vasques',
        'email' => 'helena.vasques@example.com',
        'employee_id' => '48213',
    ]);

    $certificate = Certificate::factory()->create([
        'user_id' => $holder->id,
        'certificate_number' => 'OCX-100042',
    ]);

    app(TenantContext::class)->clear();

    $this->get(route('certificates.verify', $certificate))
        ->assertOk()
        ->assertSee(__('ui.verify_valid'))
        ->assertSee('Helena Vasques')
        ->assertSee('OCX-100042')
        // Employee id, email and history stay out of the public page.
        ->assertDontSee('48213')
        ->assertDontSee('helena.vasques@example.com');
});

it('resolves by verification code, never by internal id', function (): void {
    $certificate = Certificate::factory()->create();

    $this->get('/verify/'.$certificate->id)
        ->assertOk()
        ->assertSee(__('ui.verify_not_found'));

    $this->get('/verify/'.$certificate->verification_code)
        ->assertOk()
        ->assertSee(__('ui.verify_valid'));
});

it('reports a revoked certificate as invalid', function (): void {
    $certificate = Certificate::factory()->revoked()->create();

    $this->get(route('certificates.verify', $certificate))
        ->assertOk()
        ->assertSee(__('ui.verify_revoked'));
});

it('reports an expired certificate as invalid', function (): void {
    $certificate = Certificate::factory()->expired()->create();

    $this->get(route('certificates.verify', $certificate))
        ->assertOk()
        ->assertSee(__('ui.verify_expired'));
});

it('shows a neutral message for an unknown code so certificates cannot be enumerated', function (): void {
    $this->get(route('certificates.verify', 'made-up-code'))
        ->assertOk()
        ->assertSee(__('ui.verify_not_found'));
});
