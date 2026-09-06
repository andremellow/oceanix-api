<?php

namespace App\Services\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\Permission;
use App\Enums\PlatformPermission;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\User;
use App\Services\Platform\PlatformAccess;
use Illuminate\Support\Facades\Gate;

class CoursePreviewAuthority
{
    public function authorize(Course $course, CourseVersion $version, User|Account $actor): void
    {
        $course = Course::withoutGlobalScopes()->findOrFail($course->id);
        $version = CourseVersion::query()->findOrFail($version->id);
        if ($actor instanceof Account) {
            abort_unless($actor->fresh()?->is_platform_admin && $actor->fresh()?->status === 'active', 403);
            app(PlatformAccess::class)->authorizePermission(PlatformPermission::SharedCoursesGeneratePreviewLink);
            abort_unless($course->is_shared && $course->company_id === null, 403);
        } else {
            $actor = $actor->fresh() ?? abort(403);
            abort_unless($actor->status === UserStatus::Active, 403);
            Gate::forUser($actor)->authorize(Permission::CoursesGeneratePreviewLink->value);
            Gate::forUser($actor)->authorize('generatePreviewLink', $course);
            // Deliberately after Gates: the administrator bypass cannot cross ownership.
            abort_unless(! $course->is_shared && $course->company_id !== null && (int) $course->company_id === (int) $actor->company_id, 403);
        }
        abort_unless((int) $version->course_id === (int) $course->id, 404);
        abort_unless($this->eligible($course, $version), 409, __('This preview has ended.'));
    }

    public function eligible(Course $course, CourseVersion $version): bool
    {
        return $version->status === CourseVersionStatus::Draft && $version->publication_kind === 'manual'
            && in_array($course->status, [CourseStatus::Draft, CourseStatus::Active], true)
            && (($course->is_shared && $course->company_id === null) || (! $course->is_shared && $course->company?->status === 'active'));
    }
}
