<?php

namespace App\Actions\Courses;

use App\Enums\Permission;
use App\Models\CompanyCourse;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RemoveSharedCourse
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CompanyCourse $association, User $actor, string $reason): CompanyCourse
    {
        Gate::forUser($actor)->authorize(Permission::SharedCoursesRemove->value);
        $companyId = app(TenantContext::class)->id();

        if ((int) $actor->company_id !== $companyId || (int) $association->company_id !== $companyId) {
            throw new DomainException('The association does not belong to the active company.');
        }

        if (trim($reason) === '') {
            throw new DomainException('A removal reason is required.');
        }

        return DB::transaction(function () use ($association, $actor, $reason): CompanyCourse {
            $association = CompanyCourse::query()->lockForUpdate()->findOrFail($association->id);

            if ($association->removed_at !== null) {
                return $association;
            }

            $course = $association->course;
            $activeRequirements = $course->trainingRequirements()->active()->exists();
            $openAssignments = $course->assignments()->open()->exists();

            if ($activeRequirements || $openAssignments) {
                throw new DomainException('This course cannot be removed while active requirements or open assignments depend on it.');
            }

            $association->update([
                'removed_at' => now(),
                'removed_by_user_id' => $actor->id,
                'removal_reason' => trim($reason),
            ]);

            $this->audit->log('shared_course.removed', $association, before: ['removed_at' => null], after: [
                'removed_at' => $association->removed_at?->toISOString(),
                'reason' => $association->removal_reason,
            ]);

            return $association->refresh();
        });
    }
}
