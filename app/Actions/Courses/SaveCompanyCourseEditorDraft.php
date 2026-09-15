<?php

namespace App\Actions\Courses;

use App\Enums\QuestionType;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Models\Video;
use App\Services\CourseEditor\EditorRevision;
use App\Services\CourseEditor\EditorSaveCommand;
use App\Services\Courses\LessonContentSanitizer;
use App\Services\Documents\LessonDocumentLinks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveCompanyCourseEditorDraft
{
    public function __construct(
        private readonly EditorRevision $revisions,
        private readonly LessonContentSanitizer $sanitizer,
    ) {}

    public function handle(int|Course $course, int|CourseVersion $version, User $actor, EditorSaveCommand $command): bool
    {
        $courseId = $course instanceof Course ? $course->id : $course;
        $versionId = $version instanceof CourseVersion ? $version->id : $version;

        return DB::transaction(function () use ($courseId, $versionId, $actor, $command): bool {
            $lockedCourse = Course::query()->lockForUpdate()->whereKey($courseId)->whereNotNull('company_id')->where('is_shared', false)->firstOrFail();
            $lockedVersion = CourseVersion::query()->lockForUpdate()->whereKey($versionId)->where('course_id', $lockedCourse->id)->firstOrFail();
            Gate::forUser($actor)->authorize('updateVersion', $lockedVersion);
            abort_unless($lockedVersion->isEditable(), 409);

            $compositions = $lockedVersion->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $lessons = $lockedVersion->lessons()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $questions = Question::query()->whereIn('lesson_id', $lessons->pluck('id'))->orderBy('lesson_id')->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $options = QuestionOption::query()->whereIn('question_id', $questions->pluck('id'))->orderBy('question_id')->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $videos = Video::query()->whereIn('lesson_id', $lessons->pluck('id'))->orderBy('lesson_id')->orderBy('id')->lockForUpdate()->get();
            $this->hydrateGraph($lessons, $questions, $options, $videos);

            if (! hash_equals($this->revisions->forCompanyCourse($lockedCourse, $lockedVersion, $lessons, $compositions), $command->expectedRevision())) {
                throw ValidationException::withMessages(['revision' => __('This draft changed in another session. Your unsaved changes are still here.')]);
            }

            $data = $this->validate($command);
            $this->assertIdentity($data['records'], $lessons);
            $changed = false;

            $courseValues = [
                'title' => trim($data['course']['title']),
                'description' => $data['course']['description'],
            ];
            $versionValues = [
                // The draft version title mirrors the course title until publication. Published
                // versions are never editable and therefore remain frozen by the guard above.
                'title' => trim($data['course']['title']),
                'description' => $data['version']['description'],
            ];
            $changed = $this->updateIfChanged($lockedCourse, $courseValues) || $changed;
            $changed = $this->updateIfChanged($lockedVersion, $versionValues) || $changed;

            foreach ($data['records'] as $recordData) {
                /** @var Lesson $lesson */
                $lesson = $lessons->firstWhere('id', $recordData['id']);
                $content = (string) ($recordData['content_markdown'] ?? '');
                if ($content !== (string) $lesson->content_markdown) {
                    $content = $this->sanitizer->sanitize($content);
                }
                app(LessonDocumentLinks::class)->validate($lesson, $content);
                $changed = $this->updateIfChanged($lesson, [
                    'title' => trim($recordData['title']),
                    'description' => $recordData['description'],
                    'content_markdown' => $content,
                    'is_required' => (bool) $recordData['is_required'],
                    'minimum_watch_percentage' => (int) $recordData['minimum_watch_percentage'],
                    'passing_score' => (int) $recordData['passing_score'],
                ]) || $changed;

                foreach ($recordData['questions'] as $questionData) {
                    /** @var Question $question */
                    $question = $lesson->questions->firstWhere('id', $questionData['id']);
                    $changed = $this->updateIfChanged($question, [
                        'prompt' => trim($questionData['prompt']),
                        'type' => QuestionType::from($questionData['type']),
                        'max_attempts' => (int) $questionData['max_attempts'],
                    ]) || $changed;
                    foreach ($questionData['options'] as $optionData) {
                        /** @var QuestionOption $option */
                        $option = $question->options->firstWhere('id', $optionData['id']);
                        $changed = $this->updateIfChanged($option, [
                            'text' => trim($optionData['text']),
                            'is_correct' => (bool) $optionData['is_correct'],
                        ]) || $changed;
                    }
                }
            }

            return $changed;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function validate(EditorSaveCommand $command): array
    {
        return Validator::make([
            'course' => $command->course,
            'version' => $command->version,
            'records' => $command->records,
        ], [
            'course' => ['required', 'array'],
            'course.id' => ['required', 'integer'],
            'course.title' => ['required', 'string', 'max:200'],
            'course.description' => ['nullable', 'string', 'max:2000'],
            'version.id' => ['required', 'integer'],
            'version.title' => ['required', 'string', 'max:200'],
            'version.description' => ['nullable', 'string', 'max:2000'],
            'records' => ['present', 'array'],
            'records.*.id' => ['required', 'integer'],
            'records.*.title' => ['required', 'string', 'max:200'],
            'records.*.description' => ['nullable', 'string', 'max:2000'],
            'records.*.content_markdown' => ['nullable', 'string', 'max:100000'],
            'records.*.is_required' => ['required', 'boolean'],
            'records.*.minimum_watch_percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'records.*.passing_score' => ['required', 'integer', 'min:1', 'max:100'],
            'records.*.questions' => ['present', 'array'],
            'records.*.questions.*.id' => ['required', 'integer'],
            'records.*.questions.*.prompt' => ['required', 'string', 'max:1000'],
            'records.*.questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'records.*.questions.*.max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'records.*.questions.*.options' => ['required', 'array', 'min:2'],
            'records.*.questions.*.options.*.id' => ['required', 'integer'],
            'records.*.questions.*.options.*.text' => ['required', 'string', 'max:1000'],
            'records.*.questions.*.options.*.is_correct' => ['required', 'boolean'],
        ])->after(function ($validator) use ($command): void {
            foreach ($command->records as $recordIndex => $record) {
                foreach ($record['questions'] ?? [] as $questionIndex => $question) {
                    $correct = collect($question['options'] ?? [])->where('is_correct', true)->count();
                    if (($question['type'] ?? null) === QuestionType::SingleChoice->value && $correct !== 1) {
                        $validator->errors()->add("records.{$recordIndex}.questions.{$questionIndex}.options", __('Choose exactly one correct answer.'));
                    }
                }
            }
        })->validate();
    }

    /** @param list<array<string, mixed>> $submitted */
    private function assertIdentity(array $submitted, Collection $lessons): void
    {
        $this->assertExactIds(collect($submitted)->pluck('id'), $lessons->pluck('id'), 'records');
        foreach ($submitted as $recordData) {
            $lesson = $lessons->firstWhere('id', $recordData['id']);
            $this->assertExactIds(collect($recordData['questions'])->pluck('id'), $lesson->questions->pluck('id'), 'questions');
            foreach ($recordData['questions'] as $questionData) {
                $question = $lesson->questions->firstWhere('id', $questionData['id']);
                $this->assertExactIds(collect($questionData['options'])->pluck('id'), $question->options->pluck('id'), 'options');
            }
        }
    }

    private function assertExactIds(Collection $submitted, Collection $persisted, string $field): void
    {
        if ($submitted->duplicates()->isNotEmpty() || $submitted->sort()->values()->all() !== $persisted->sort()->values()->all()) {
            throw ValidationException::withMessages([$field => __('One or more editor records are unavailable.')]);
        }
    }

    private function hydrateGraph(Collection $lessons, Collection $questions, Collection $options, Collection $videos): void
    {
        $groupedQuestions = $questions->groupBy('lesson_id');
        $groupedOptions = $options->groupBy('question_id');
        $currentVideos = $videos->where('is_current', true)->groupBy('lesson_id');
        $questions->each(fn (Question $question) => $question->setRelation('options', $groupedOptions->get($question->id, collect())));
        $lessons->each(function (Lesson $lesson) use ($groupedQuestions, $currentVideos): void {
            $lesson->setRelation('questions', $groupedQuestions->get($lesson->id, collect()));
            $lesson->setRelation('video', $currentVideos->get($lesson->id, collect())->last());
        });
    }

    /** @param array<string, mixed> $values */
    private function updateIfChanged($model, array $values): bool
    {
        $model->fill($values);
        if (! $model->isDirty()) {
            return false;
        }
        $model->save();

        return true;
    }
}
