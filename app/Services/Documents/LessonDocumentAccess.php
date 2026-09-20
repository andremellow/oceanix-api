<?php

namespace App\Services\Documents;

use App\Enums\ModuleVersionStatus;
use App\Enums\PlatformPermission;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Courses\PlatformCoursePreview;
use App\Services\Courses\PublicPreviewResolver;
use App\Services\Platform\PlatformAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LessonDocumentAccess
{
    public function training(User $actor, UserTrainingAssignment $assignment, Lesson $lesson, string $document): StreamedResponse
    {
        $actor = $actor->fresh();
        abort_unless($actor->status === UserStatus::Active, 403);
        $assignment = $assignment->fresh();
        abort_unless($assignment->user_id === $actor->id && $assignment->status->isOpen() && $assignment->isAvailable(), 403);
        Gate::forUser($actor)->authorize('execute', $assignment);
        abort_unless($assignment->includesLesson($lesson), 404);

        return $this->response($lesson->fresh(), $document);
    }

    public function company(User $actor, Course $course, Lesson $lesson, string $document): StreamedResponse
    {
        $actor = $actor->fresh();
        $course = $course->fresh();
        abort_if($course->is_shared, 404);
        abort_unless($actor->status === UserStatus::Active && (int) $actor->company_id === (int) $course->company_id, 403);
        Gate::forUser($actor->fresh())->authorize('update', $course);
        abort_unless($course->versions()->whereKey($lesson->course_version_id)->exists(), 404);

        return $this->response($lesson->fresh(), $document);
    }

    public function platformCourse(Course $course, CourseVersion $version, string $kind, string $item, string $document): StreamedResponse
    {
        return $this->response(app(PlatformCoursePreview::class)->lesson($course, $version, $kind, $item), $document);
    }

    public function platformModule(Module $module, string $document): StreamedResponse
    {
        $account = app(PlatformAccess::class)->authorizePermission(PlatformPermission::SharedModulesView);
        abort_unless(Account::query()->whereKey($account->id)->where('status', 'active')->where('is_platform_admin', true)->exists(), 403);
        $module = $module->fresh();
        abort_unless($module->is_shared && $module->company_id === null, 404);
        $version = $module->versions()->where('status', ModuleVersionStatus::Draft->value)->firstOrFail();

        return $this->response($version, $document);
    }

    public function preview(string $token, string $kind, string $item, string $document): StreamedResponse
    {
        $resolver = app(PublicPreviewResolver::class);

        return $this->response($resolver->item($resolver->resolve($token), $kind, $item), $document);
    }

    private function response(Lesson $lesson, string $id): StreamedResponse
    {
        abort_unless(in_array($id, app(LessonDocumentLinks::class)->ids((string) $lesson->content_markdown), true), 404);
        $document = $lesson->documents()->where('public_id', $id)->firstOrFail();
        $stream = rescue(fn () => Storage::disk($document->disk)->readStream($document->path), false);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $document->name, 'document.pdf'),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
