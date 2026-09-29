<?php

use Illuminate\Support\Facades\Http;
use Tests\Support\ComplianceAccessFixture;

it('SC-02 SC-04 blocks the next request of an already-open employee or administrator session', function ($admin) {
    Http::preventStrayRequests();
    Http::fake();
    [$user,$company] = ComplianceAccessFixture::create($admin);
    $this->actingAs($user);
    $page = visit('/c/'.$company->slug.'/dashboard')->assertNoJavaScriptErrors();
    $company->update(['compliance_access_enabled' => false]);
    $page->refresh()->assertSee('403')->assertNoJavaScriptErrors();
    $company->update(['compliance_access_enabled' => true]);
    $page->refresh()->assertDontSee('403')->assertNoJavaScriptErrors();
})->with([false, true]);
