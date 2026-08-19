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
        return $user->hasPermission(Permission::CoursesView);
    }

    public function view(User $user, Course $course): bool
    {
        return $user->hasPermission(Permission::CoursesView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::CoursesCreate);
    }

    /** Only a draft version can be edited — publishing freezes the content. */
    public function update(User $user, Course $course): bool
    {
        return $user->hasPermission(Permission::CoursesUpdate);
    }

    public function updateVersion(User $user, CourseVersion $version): bool
    {
        return $version->status === CourseVersionStatus::Draft
            && $user->hasPermission(Permission::CoursesUpdate);
    }

    public function publish(User $user, Course $course): bool
    {
        return $user->hasPermission(Permission::CoursesPublish);
    }

    public function retire(User $user, Course $course): bool
    {
        return $user->hasPermission(Permission::CoursesRetire);
    }
}
