<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\ModuleVersion;
use App\Services\CourseEditor\EditorRevision;
use App\Services\Modules\ModuleLineageLock;
use App\Services\Modules\SharedModuleDraftWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class SaveSharedCourseEditorDraft
{
    public function __construct(
        private readonly SharedModuleDraftWriter $writer,
        private readonly EditorRevision $revisions,
        private readonly ?ModuleLineageLock $lineageLock = null,
    ) {}

    public function handle(Course $course, CourseVersion $version, Account $actor, array $courseData, array $versionData, array $modules, string $expectedRevision, array $moduleRevisions): array
    {
        return DB::transaction(function () use ($course, $version, $actor, $courseData, $versionData, $modules, $expectedRevision, $moduleRevisions): array {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            if ($authorized === null) {
                throw new LogicException('Only an active platform administrator can edit shared content.');
            }
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);
            $lockedVersion = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if (! $lockedCourse->is_shared || $lockedCourse->company_id !== null || $lockedCourse->status === CourseStatus::Archived || $lockedVersion->course_id !== $lockedCourse->id || $lockedVersion->status !== CourseVersionStatus::Draft || $lockedVersion->publication_kind !== 'manual') {
                throw new LogicException('Only platform-owned shared course drafts can be saved.');
            }
            $compositions = CourseVersionModule::query()->where('course_version_id', $lockedVersion->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->revision($lockedCourse, $lockedVersion, $compositions), $expectedRevision)) {
                throw ValidationException::withMessages(['revision' => __('This course changed elsewhere. Reload the page before saving again.')]);
            }
            $data = Validator::make(['course' => $courseData, 'version' => $versionData], [
                'course.code' => ['required', 'string', 'max:40'], 'course.title' => ['required', 'string', 'max:200'],
                'course.description' => ['nullable', 'string', 'max:2000'], 'version.description' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            $normalizedCode = strtoupper(trim($data['course']['code']));
            if (Course::query()->whereKeyNot($lockedCourse->id)->whereNull('company_id')->where('is_shared', true)->whereRaw('UPPER(code) = ?', [$normalizedCode])->exists()) {
                throw ValidationException::withMessages(['courseForm.code' => __('A shared course with this code already exists.')]);
            }
            if (collect($modules)->pluck('id')->all() !== $compositions->pluck('lesson_id')->all()) {
                throw ValidationException::withMessages(['modules' => __('One or more modules are unavailable.')]);
            }
            $lockedModules = ($this->lineageLock ?? app(ModuleLineageLock::class))->versions($compositions->pluck('lesson_id'))->whereIn('id', $compositions->pluck('lesson_id'))->keyBy('id');
            if ($lockedModules->count() !== $compositions->count() || $lockedModules->contains(fn (ModuleVersion $module): bool => $module->lineage_archived_at !== null)) {
                throw ValidationException::withMessages(['modules' => __('One or more modules are unavailable.')]);
            }
            $prepared = [];
            foreach ($modules as $moduleIndex => $modulePayload) {
                $module = $lockedModules->get($modulePayload['id']);
                try {
                    $prepared[] = $this->writer->prepare($module, $modulePayload, $moduleRevisions[$module->id] ?? '');
                } catch (ValidationException $exception) {
                    if (array_key_exists('revision', $exception->errors())) {
                        throw $exception;
                    }
                    $mapped = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $mapped["records.{$moduleIndex}.{$field}"] = $messages;
                    }

                    throw ValidationException::withMessages($mapped);
                }
            }
            $this->updateIfChanged($lockedCourse, ['code' => $normalizedCode, 'title' => trim($data['course']['title']), 'description' => $data['course']['description']]);
            $this->updateIfChanged($lockedVersion, ['title' => trim($data['course']['title']), 'description' => $data['version']['description']]);
            foreach ($prepared as $modulePrepared) {
                $this->writer->write($modulePrepared);
            }

            return [
                'course_revision' => $this->revision($lockedCourse->fresh(), $lockedVersion->fresh(), $compositions),
                'module_revisions' => collect($prepared)->mapWithKeys(fn (array $item): array => [$item['module']->id => $this->writer->revision($item['module']->fresh())])->all(),
            ];
        });
    }

    public function revision(Course $course, CourseVersion $version, $compositions = null): string
    {
        return $this->revisions->forSharedCourse($course, $version, $compositions);
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
