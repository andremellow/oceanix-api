<?php

namespace App\Services\People;

use App\Models\Department;
use App\Models\JobFunction;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use Illuminate\Database\Eloquent\Builder;

class PeopleDirectory
{
    public function candidates(): Builder
    {
        return User::query()->whereIn('status', ['active', 'invited'])->where('workos_active_membership', false)->where(fn ($q) => $q->whereNull('invitation_state')->orWhereIn('invitation_state', ['not_invited', 'pending', 'expired', 'unverified']));
    }

    public function query(array $filters = []): Builder
    {
        $q = User::query()->with(['departments', 'jobFunctions'])->withCount(['assignments as open_assignments_count' => fn ($q) => $q->open(), 'assignments as overdue_assignments_count' => fn ($q) => $q->overdue()]);
        if (filled($filters['search'] ?? null)) {
            $term = '%'.strtolower($filters['search']).'%';
            $q->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(email) like ?', [$term])->orWhereRaw("lower(coalesce(employee_id, '')) like ?", [$term]));
        }
        if (filled($filters['department_id'] ?? null)) {
            $q->inDepartment((int) $filters['department_id']);
        }
        if (filled($filters['job_function_id'] ?? null)) {
            $q->inJobFunction((int) $filters['job_function_id']);
        }
        if (filled($filters['status'] ?? null)) {
            $q->where('status', $filters['status']);
        }
        if (filled($filters['invitation_status'] ?? null)) {
            $state = $filters['invitation_status'];
            $q->where(fn ($q) => $q->where('invitation_state', $state)->when($state === 'unverified', fn ($q) => $q->orWhereNull('invitation_state')->orWhereNotNull('invitation_check_error')));
        }
        if ($filters['no_access'] ?? false) {
            $q->whereNull('first_access_at');
        }

        return $q;
    }

    public function data(array $filters = []): array
    {
        $latest = WorkosSyncRun::query()->latest('id')->first();
        $attempts = WorkosInvitationAttempt::query()->latest('id')->limit(20)->get();
        $names = User::query()->whereKey($attempts->pluck('person_id'))->pluck('name', 'id');

        return ['people' => $this->query($filters)->orderBy('name')->orderBy('id')->paginate(25), 'departments' => Department::query()->active()->orderBy('name')->get(), 'jobFunctions' => JobFunction::query()->active()->orderBy('name')->get(), 'pendingInvitationsCount' => $this->candidates()->count(), 'latestSync' => $latest, 'lastSuccessfulSync' => WorkosSyncRun::query()->where('status', 'completed')->latest('finished_at')->first(), 'invitationAttempts' => $attempts, 'attemptNames' => $names, 'operationsActive' => ($latest?->status->isOpen() ?? false) || WorkosInvitationAttempt::query()->whereIn('status', ['queued', 'running', 'sending'])->exists()];
    }

    public function detail(User $person): array
    {
        return ['personAttempt' => WorkosInvitationAttempt::query()->where('person_id', $person->id)->latest('id')->first()];
    }

    public static function reason(?string $reason): string
    {
        return __(match ($reason) {
            'already_accepted' => 'Invitation already accepted','current_member' => 'Already a member of this company','suspended' => 'Person is suspended','terminated' => 'Person is terminated','permission_revoked' => 'Permission revoked','provider_not_configured' => 'WorkOS is not configured.','provider_verification_failed' => 'Provider verification failed','invitation_unavailable' => 'Invitation unavailable','revoked_default' => 'Revoked invitations require explicit selection','superseded' => 'A newer invitation change was preserved','delivery_unconfirmed' => 'Delivery unconfirmed. Verify before trying again.','send_failed' => 'Invitation could not be sent','queue_unavailable' => 'Queue unavailable','synchronization_failed' => 'Synchronization failed',default => 'No issue recorded'
        });
    }
}
