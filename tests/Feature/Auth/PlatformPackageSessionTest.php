<?php

use App\Models\Account;

it('uses the shared logout controller for the company session and clears its data', function () {
    $this->withSession(['logout_marker' => 'remove-me'])->actingAs(employeeUser());
    $this->assertAuthenticated();

    $this->post(route('logout'))->assertRedirect(route('login'))
        ->assertSessionMissing('logout_marker');

    $this->assertGuest();
});

it('uses the same shared logout controller to clear the global platform session', function () {
    $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active']);
    $this->withSession(['platform_account_id' => $account->id, 'logout_marker' => 'remove-me']);

    $this->post(route('platform.logout'))->assertRedirect(route('login'))
        ->assertSessionMissing('platform_account_id')->assertSessionMissing('logout_marker');

    $this->assertGuest();
});
