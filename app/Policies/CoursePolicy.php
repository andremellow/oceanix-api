<?php

namespace App\Policies;

use App\Enums\CourseVersionStatus;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\User;

class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin()
            || $user->hasPermission(Permission::CoursesView);
    }

    public function view(User $user, Course $course): bool
    {
        if ($course->is_shared) {
            return $user->isPlatformAdmin()
                || $user->hasPermission(Permission::SharedCoursesView);
        }

        return $this->belongsToUserCompany($user, $course)
            && $user->hasPermission(Permission::CoursesView);
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin()
            || $user->hasPermission(Permission::CoursesCreate);
    }

    /** Only a draft version can be edited — publishing freezes the content. */
    public function update(User $user, Course $course): bool
    {
        return $this->canWrite($user, $course, Permission::CoursesUpdate);
    }

    public function updateVersion(User $user, CourseVersion $version): bool
    {
        return $version->status === CourseVersionStatus::Draft
            && $this->canWrite($user, $version->course, Permission::CoursesUpdate);
    }

    public function publish(User $user, Course $course): bool
    {
        return $this->canWrite($user, $course, Permission::CoursesPublish);
    }

    public function retire(User $user, Course $course): bool
    {
        return $this->canWrite($user, $course, Permission::CoursesRetire);
    }

    private function canWrite(User $user, Course $course, Permission $permission): bool
    {
        if ($course->is_shared) {
            return $user->isPlatformAdmin();
        }

        return $this->belongsToUserCompany($user, $course)
            && $user->hasPermission($permission);
    }

    private function belongsToUserCompany(User $user, Course $course): bool
    {
        return $course->company_id !== null
            && (int) $course->company_id === (int) $user->company_id;
    }
}
