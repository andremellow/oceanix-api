<?php

namespace App\Services\Courses;

use App\Actions\Courses\GenerateCoursePreviewLink;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class CoursePreviewPanel
{
    public function __construct(private GenerateCoursePreviewLink $links) {}

    /** @return array{endpoint: string, link: array<string, mixed>}|null */
    public function forCompany(Course $course, CourseVersion $version, User $actor): ?array
    {
        return $this->project($course, $version, $actor, false);
    }

    /** @return array{endpoint: string, link: array<string, mixed>}|null */
    public function forPlatform(Course $course, CourseVersion $version, Account $actor): ?array
    {
        return $this->project($course, $version, $actor, true);
    }

    /** @return array{endpoint: string, link: array<string, mixed>}|null */
    private function project(Course $course, CourseVersion $version, User|Account $actor, bool $platform): ?array
    {
        try {
            $link = $this->links->retrieve($course->fresh(), $version->fresh(), $actor);
        } catch (AuthorizationException $exception) {
            return null;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 403) {
                return null;
            }

            throw $exception;
        }

        return [
            'endpoint' => $platform
                ? route('platform.shared-courses.preview-link', ['course' => $course, 'version' => $version])
                : route('courses.preview-link', ['company' => $course->company()->firstOrFail(), 'course' => $course, 'version' => $version]),
            'link' => $link,
        ];
    }
}
