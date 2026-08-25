<?php

use App\Models\User;

it('lets a local company administrator impersonate and return from a user', function (): void {
    $administrator = adminUser();
    $target = User::factory()->create(['name' => 'Test Employee']);

    $this->actingAs($administrator)
        ->post(route('impersonation.start', ['company' => currentCompany(), 'user' => $target]))
        ->assertRedirect(route('dashboard', ['company' => currentCompany()]));

    expect(auth()->id())->toBe($target->id)
        ->and(session('impersonator_user_id'))->toBe($administrator->id)
        ->and(session('impersonator_name'))->toBe($administrator->name);

    $this->get(route('dashboard', ['company' => currentCompany()]))
        ->assertOk()
        ->assertSee('Test Employee')
        ->assertSee(__('Return to :name', ['name' => $administrator->name]));

    $this->post(route('impersonation.stop', ['company' => currentCompany()]))
        ->assertRedirect(route('dashboard', ['company' => currentCompany()]));

    expect(auth()->id())->toBe($administrator->id)
        ->and(session()->has('impersonator_user_id'))->toBeFalse();
});

it('denies impersonation to a non administrator', function (): void {
    $employee = employeeUser();
    $target = User::factory()->create();

    $this->actingAs($employee)
        ->post(route('impersonation.start', ['company' => currentCompany(), 'user' => $target]))
        ->assertForbidden();

    expect(auth()->id())->toBe($employee->id)
        ->and(session()->has('impersonator_user_id'))->toBeFalse();
});

it('does not allow nested impersonation', function (): void {
    $administrator = adminUser();
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($administrator)
        ->post(route('impersonation.start', ['company' => currentCompany(), 'user' => $first]))
        ->assertRedirect();

    $this->post(route('impersonation.start', ['company' => currentCompany(), 'user' => $second]))
        ->assertForbidden();

    expect(auth()->id())->toBe($first->id);
});
