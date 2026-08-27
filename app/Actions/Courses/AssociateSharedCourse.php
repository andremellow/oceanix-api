<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\Permission;
use App\Models\CompanyCourse;
use App\Models\Course;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AssociateSharedCourse
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Course $course, User $actor): CompanyCourse
    {
        Gate::forUser($actor)->authorize(Permission::SharedCoursesAdd->value);
        $companyId = app(TenantContext::class)->id();

        if ((int) $actor->company_id !== $companyId) {
            throw new DomainException('The user does not belong to the active company.');
        }

        if (! $course->is_shared || $course->company_id !== null
            || $course->status !== CourseStatus::Active || $course->current_published_version_id === null) {
            throw new DomainException('This shared course is not available to add.');
        }

        return DB::transaction(function () use ($course, $actor, $companyId): CompanyCourse {
            $course = Course::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($course->id);
            if (! $course->is_shared || $course->company_id !== null
                || $course->status !== CourseStatus::Active || $course->current_published_version_id === null) {
                throw new DomainException('This shared course is not available to add.');
            }

            $association = CompanyCourse::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)->where('course_id', $course->id)
                ->lockForUpdate()->first();

            if ($association?->removed_at === null && $association !== null) {
                return $association;
            }

            if ($association === null) {
                $now = now();
                $created = DB::table('company_courses')->insertOrIgnore([
                    'company_id' => $companyId,
                    'course_id' => $course->id,
                    'associated_at' => $now,
                    'associated_by_user_id' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $association = CompanyCourse::query()->withoutGlobalScopes()
                    ->where('company_id', $companyId)->where('course_id', $course->id)
                    ->lockForUpdate()->firstOrFail();

                if ($created === 0 && $association->removed_at === null) {
                    return $association;
                }

                if ($created === 0) {
                    $association->update([
                        'associated_at' => now(),
                        'associated_by_user_id' => $actor->id,
                        'removed_at' => null,
                        'removed_by_user_id' => null,
                        'removal_reason' => null,
                    ]);
                }
            } else {
                $association->update([
                    'associated_at' => now(),
                    'associated_by_user_id' => $actor->id,
                    'removed_at' => null,
                    'removed_by_user_id' => null,
                    'removal_reason' => null,
                ]);
            }

            $this->audit->log('shared_course.associated', $association, after: [
                'course_id' => $course->id,
                'company_id' => $companyId,
            ]);

            return $association->refresh();
        });
    }
}
