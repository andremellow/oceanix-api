<?php

namespace App\Actions\Courses;

use App\Models\Account;
use App\Models\Course;
use App\Models\CoursePreviewLink;
use App\Models\CourseVersion;
use App\Models\User;
use App\Services\Courses\CoursePreviewAuthority;
use Illuminate\Support\Facades\DB;

class GenerateCoursePreviewLink
{
    public function __construct(private readonly CoursePreviewAuthority $authority) {}

    public function handle(Course $course, CourseVersion $version, User|Account $actor): array
    {
        return $this->generate($course, $version, $actor)['link'];
    }

    /** @return array{link: array, created: bool} */
    public function generate(Course $course, CourseVersion $version, User|Account $actor): array
    {
        $this->authority->authorize($course, $version, $actor);

        return DB::transaction(function () use ($course, $version, $actor): array {
            $course = Course::withoutGlobalScopes()->lockForUpdate()->findOrFail($course->id);
            $version = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            $this->authority->authorize($course, $version, $actor);
            $link = $this->active($version);
            $created = $link === null;
            if ($created) {
                $token = bin2hex(random_bytes(32));
                $clock = now()->startOfSecond();
                $link = CoursePreviewLink::query()->create([
                    'course_version_id' => $version->id, 'token_hash' => hash('sha256', $token), 'token_encrypted' => $token,
                    'generated_at' => $clock, 'expires_at' => $clock->copy()->addHours(168),
                    'generated_by_user_id' => $actor instanceof User ? $actor->id : null,
                    'generated_by_account_id' => $actor instanceof Account ? $actor->id : null,
                ]);
            }

            return ['link' => $this->dto($link), 'created' => $created];
        });
    }

    public function retrieve(Course $course, CourseVersion $version, User|Account $actor): array
    {
        $this->authority->authorize($course, $version, $actor);
        $link = $this->active($version);

        return $link ? $this->dto($link) : ['state' => CoursePreviewLink::query()->where('course_version_id', $version->id)->exists() ? 'expired' : 'absent', 'url' => null, 'expires_at' => null];
    }

    private function active(CourseVersion $version): ?CoursePreviewLink
    {
        return CoursePreviewLink::query()->where('course_version_id', $version->id)->where('expires_at', '>', now())->latest('id')->first();
    }

    private function dto(CoursePreviewLink $link): array
    {
        return ['state' => 'active', 'url' => route('course-preview.show', ['token' => $link->token_encrypted]), 'expires_at' => $link->expires_at->toIso8601String()];
    }
}
