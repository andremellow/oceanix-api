<?php

use App\Enums\WorkosOperationStatus;
use App\Jobs\ReconcileWorkosInvitations;
use App\Jobs\SendWorkosInvitation;
use App\Models\Company;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

it('SC-22 quarantines serialized legacy send and sync jobs without any external request and restores tenant context', function () {
    Http::preventStrayRequests();
    $company = currentCompany();
    $actor = adminUser();
    $person = employeeUser();
    $attempt = WorkosInvitationAttempt::create(['person_id' => $person->id, 'actor_id' => $actor->id, 'mode' => 'selected', 'generation' => 0, 'status' => WorkosOperationStatus::Queued]);
    $run = WorkosSyncRun::create(['actor_id' => $actor->id, 'status' => WorkosOperationStatus::Queued]);
    $other = Company::factory()->create();
    app(TenantContext::class)->set($other);
    (new SendWorkosInvitation($company->id, $person->id, $actor->id, $attempt->id))->handle(app(App\Actions\People\SendWorkosInvitation::class));
    (new ReconcileWorkosInvitations($company->id, $actor->id, $run->id))->handle(app(App\Actions\People\ReconcileWorkosInvitations::class));
    expect($attempt->fresh()->reason)->toBe('legacy_cutover_review')->and($run->fresh()->reason)->toBe('legacy_cutover_review')->and(app(TenantContext::class)->id())->toBe($other->id);
    Http::assertNothingSent();
});
