<?php

namespace App\Services\CourseEditor;

use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\ModuleVersion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class EditorRevision
{
    public function assertExpected(string $expected): void
    {
        if (blank($expected)) {
            throw ValidationException::withMessages([
                'revision' => __('This draft changed in another session. Reload it before changing the structure.'),
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    public function calculate(array $payload): string
    {
        return hash('sha256', json_encode($this->normalize($payload), JSON_THROW_ON_ERROR));
    }

    public function forCompanyCourse(Course $course, CourseVersion $version, ?Collection $lessons = null, ?Collection $compositions = null): string
    {
        $lessons ??= $version->lessons()->with(['video', 'questions.options'])->orderBy('position')->orderBy('id')->get();
        $compositions ??= $version->moduleCompositions()->orderBy('position')->orderBy('id')->get();

        return $this->calculate([
            'course' => $course->only(['id', 'code', 'title', 'description']),
            'version' => $version->only(['id', 'title', 'description']),
            'composition' => $compositions->map(fn ($row): array => [
                'id' => $row->id,
                'record_id' => $row->lesson_id,
                'position' => $row->position,
                'required' => (bool) $row->is_required,
            ])->all(),
            'records' => $lessons->map(fn ($lesson): array => $this->record($lesson))->all(),
        ]);
    }

    public function forSharedCourse(Course $course, CourseVersion $version, ?Collection $compositions = null): string
    {
        $compositions ??= $version->moduleCompositions()->orderBy('position')->orderBy('id')->get();

        return $this->calculate([
            'course' => $course->only(['id', 'code', 'title', 'description']),
            'version' => $version->only(['id', 'title', 'description']),
            'composition' => $compositions->map(fn ($row): array => [
                'id' => $row->id,
                'record_id' => $row->lesson_id,
                'position' => $row->position,
                'required' => (bool) $row->is_required,
            ])->all(),
        ]);
    }

    public function forSharedModule(ModuleVersion $version, ?Collection $questions = null): string
    {
        $questions ??= $version->questions()->with('options')->orderBy('position')->orderBy('id')->get();
        $version->setRelation('questions', $questions);

        return $this->calculate($this->record($version));
    }

    /** @param list<array<string, mixed>> $records */
    public function assertPersistedKeys(array $records): void
    {
        foreach ($records as $record) {
            foreach ([$record, ...($record['questions'] ?? [])] as $candidate) {
                $key = (string) ($candidate['key'] ?? '');
                if (($candidate['id'] ?? null) === null || str_starts_with($key, 'tmp:')) {
                    throw ValidationException::withMessages(['structure' => __('Save or discard structural changes before saving authored fields.')]);
                }
                foreach ($candidate['options'] ?? [] as $option) {
                    if (($option['id'] ?? null) === null || str_starts_with((string) ($option['key'] ?? ''), 'tmp:')) {
                        throw ValidationException::withMessages(['structure' => __('Save or discard structural changes before saving authored fields.')]);
                    }
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function record($record): array
    {
        return [
            'id' => $record->id,
            'title' => $record->title,
            'description' => $record->description,
            'content_markdown' => $record->content_markdown,
            'is_required' => (bool) $record->is_required,
            'minimum_watch_percentage' => (int) $record->minimum_watch_percentage,
            'passing_score' => (int) $record->passing_score,
            'position' => (int) $record->position,
            'video_id' => $record->video?->id,
            'questions' => $record->questions->sortBy('position')->values()->map(fn ($question): array => [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'type' => $question->type->value,
                'max_attempts' => (int) $question->max_attempts,
                'position' => (int) $question->position,
                'options' => $question->options->sortBy('position')->values()->map(fn ($option): array => [
                    'id' => $option->id,
                    'text' => $option->text,
                    'is_correct' => (bool) $option->is_correct,
                    'position' => (int) $option->position,
                ])->all(),
            ])->all(),
        ];
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
