<?php

namespace App\Actions\People;

use App\Enums\Permission;
use App\Enums\WorkosOperationStatus;
use App\Jobs\SendWorkosInvitation;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Services\People\PeopleDirectory;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class QueueWorkosInvitations
{
    public function handle(array $personIds = [], bool $allPending = false): int
    {
        Gate::authorize(Permission::PeopleInvite->value);
        $company = app(TenantContext::class)->get();
        abort_unless($company && auth()->user()->company_id === $company->id, 403);
        if (blank($company->workos_organization_id)) {
            throw new RuntimeException(__('Provision this company in WorkOS before sending invitations.'));
        }
        Validator::make(['ids' => $personIds], ['ids' => 'array|max:500', 'ids.*' => 'integer|min:1|distinct'])->validate();
        $q = $allPending ? app(PeopleDirectory::class)->candidates() : User::query()->whereKey($personIds);
        $count = 0;
        $q->chunkById(100, function ($people) use (&$count, $allPending, $company) {
            foreach ($people as $person) {
                Gate::authorize('invite', $person);
                $attempt = DB::transaction(function () use ($person, $allPending) {
                    $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
                    if (WorkosInvitationAttempt::query()->where('person_id', $locked->id)->whereIn('status', ['queued', 'running', 'sending'])->exists()) {
                        return null;
                    }
                    $attempt = WorkosInvitationAttempt::query()->create(['person_id' => $locked->id, 'actor_id' => auth()->id(), 'mode' => $allPending ? 'default' : 'selected', 'generation' => $locked->invitation_generation, 'status' => WorkosOperationStatus::Queued]);

                    return $attempt;
                });
                if (! $attempt) {
                    continue;
                }$count++;
                DB::afterCommit(function () use ($attempt, $company) {
                    try {
                        app(DispatchWorkosOperation::class)->handle(new SendWorkosInvitation($company->id, $attempt->person_id, $attempt->actor_id, $attempt->id));
                    } catch (\Throwable) {
                        $attempt->update(['status' => WorkosOperationStatus::Failed, 'reason' => 'queue_unavailable', 'finished_at' => now()]);
                    }
                });
            }
        });

        return $count;
    }
}
