<?php

namespace App\Actions\People;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Jobs\SendWorkosInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class QueueWorkosInvitations
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<int> $personIds */
    public function handle(array $personIds = [], bool $allPending = false): int
    {
        Gate::authorize(Permission::PeopleInvite->value);
        $company = $this->tenant->get();

        if ($company === null || blank($company->workos_organization_id)) {
            throw new RuntimeException(__('Provision this company in WorkOS before sending invitations.'));
        }

        $query = User::query()->whereIn('status', [UserStatus::Active, UserStatus::Invited]);

        if ($allPending) {
            $query->whereNull('workos_invitation_id');
        } else {
            $ids = collect($personIds)->map(fn ($id): int => (int) $id)->filter()->unique()->take(500);
            $query->whereKey($ids);
        }

        $ids = $query->pluck('id');

        foreach ($ids as $personId) {
            SendWorkosInvitation::dispatch($company->id, $personId, auth()->id());
        }

        $this->audit->log('people.workos_invitations_queued', metadata: [
            'count' => $ids->count(),
            'all_pending' => $allPending,
        ]);

        return $ids->count();
    }
}
