<?php

namespace App\Actions\Courses;

use App\Enums\QuestionType;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Services\Courses\LessonContentSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateCourseEditorField
{
    public function __construct(private readonly LessonContentSanitizer $sanitizer) {}

    public function handle(CourseVersion $version, User $actor, string $level, int $recordId, string $field, mixed $value): mixed
    {
        return DB::transaction(function () use ($version, $actor, $level, $recordId, $field, $value): mixed {
            $courseId = CourseVersion::query()->whereKey($version->id)->firstOrFail(['course_id'])->course_id;
            $course = Course::query()->lockForUpdate()->findOrFail($courseId);
            $version = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            abort_unless((int) $version->course_id === (int) $course->id, 404);
            Gate::forUser($actor)->authorize('updateVersion', $version);

            [$record, $rules] = match ($level) {
                'course' => [$this->course($course, $recordId), ['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000']]],
                'version' => [$this->version($version, $recordId), ['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000']]],
                'lesson' => [$this->lesson($version, $recordId), [
                    'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'],
                    'content_markdown' => ['nullable', 'string', 'max:100000'], 'is_required' => ['boolean'],
                    'minimum_watch_percentage' => ['required', 'integer', 'min:1', 'max:100'], 'passing_score' => ['required', 'integer', 'min:1', 'max:100'],
                ]],
                'question' => [$this->question($version, $recordId), ['prompt' => ['required', 'string', 'max:1000'], 'type' => [Rule::enum(QuestionType::class)], 'max_attempts' => ['required', 'integer', 'min:1', 'max:10']]],
                'option' => [$this->option($version, $recordId), ['text' => ['nullable', 'string', 'max:1000'], 'is_correct' => ['boolean']]],
                default => throw ValidationException::withMessages(['editor' => __('ui.editor_field_unavailable')]),
            };

            if (! array_key_exists($field, $rules)) {
                throw ValidationException::withMessages(['editor' => __('ui.editor_field_unavailable')]);
            }

            $validated = validator([$field => $value], [$field => $rules[$field]])->validate();
            $value = $field === 'content_markdown' ? $this->sanitizer->sanitize((string) $validated[$field]) : $validated[$field];
            $record->update([$field => $value]);

            if ($level === 'course' && $field === 'title') {
                $version->update(['title' => $value]);
            }
            if ($level === 'question' && $field === 'type' && $value === QuestionType::SingleChoice->value) {
                $correct = $record->options()->where('is_correct', true)->orderBy('position')->get();
                $record->options()->where('is_correct', true)->update(['is_correct' => false]);
                $correct->first()?->update(['is_correct' => true]);
            }

            return $value;
        });
    }

    private function course(Course $course, int $id): Course
    {
        abort_unless($course->id === $id, 404);

        return $course;
    }

    private function version(CourseVersion $version, int $id): CourseVersion
    {
        abort_unless($version->id === $id, 404);

        return $version;
    }

    private function lesson(CourseVersion $version, int $id): Lesson
    {
        return $version->lessons()->lockForUpdate()->findOrFail($id);
    }

    private function question(CourseVersion $version, int $id): Question
    {
        return Question::query()->whereHas('lesson', fn ($q) => $q->where('course_version_id', $version->id))->lockForUpdate()->findOrFail($id);
    }

    private function option(CourseVersion $version, int $id): QuestionOption
    {
        return QuestionOption::query()->whereHas('question.lesson', fn ($q) => $q->where('course_version_id', $version->id))->lockForUpdate()->findOrFail($id);
    }
}
