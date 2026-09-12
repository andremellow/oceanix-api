<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Creates the permanent course identity together with its first draft version. */
class CreateCourse
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(string $code, string $title, ?string $description = null, ?Account $platformActor = null, ?User $actor = null): Course
    {
        if ($platformActor !== null && ! $platformActor->is_platform_admin) {
            throw new LogicException('Only a platform administrator can create shared content.');
        }

        $code = mb_strtoupper(trim($code));
        $companyId = $platformActor === null ? app(TenantContext::class)->id() : null;
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', Course::class);
            if ((int) $actor->company_id !== (int) $companyId) {
                throw new LogicException('The course tenant does not match the actor tenant.');
            }
        }
        if ($platformActor === null && Course::withoutGlobalScopes()->where('company_id', $companyId)->where('is_shared', false)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('ui.course_code_taken')]);
        }

        try {
            return DB::transaction(function () use ($code, $title, $description, $platformActor, $companyId): Course {
                $course = Course::query()->create([
                    'company_id' => $companyId,
                    'is_shared' => $platformActor !== null,
                    'code' => $code,
                    'title' => trim($title),
                    'description' => $description,
                    'status' => CourseStatus::Draft,
                ]);

                CourseVersion::query()->create([
                    'course_id' => $course->id,
                    'version_number' => 1,
                    'status' => CourseVersionStatus::Draft,
                    'title' => $course->title,
                    'description' => $course->description,
                    'published_by_account_id' => $platformActor?->id,
                ]);

                if ($platformActor === null) {
                    $this->audit->log('course.created', $course, after: [
                        'code' => $course->code,
                        'title' => $course->title,
                    ]);
                }

                return $course->refresh();
            });
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
            if ($platformActor === null && (str_contains($message, 'courses_company_code_unique') || str_contains($message, 'courses.company_id, courses.code'))) {
                throw ValidationException::withMessages(['code' => __('ui.course_code_taken')]);
            }

            throw $exception;
        }
    }
}
