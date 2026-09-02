<?php

namespace App\Actions\Courses;

use App\Enums\CourseVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\ModuleVersion;
use App\Services\Modules\SharedModuleDraftWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class SaveSharedCourseEditorDraft
{
    public function __construct(private readonly SharedModuleDraftWriter $writer) {}

    public function handle(Course $course, CourseVersion $version, Account $actor, array $courseData, array $versionData, array $modules, string $expectedRevision, array $moduleRevisions): array
    {
        return DB::transaction(function () use ($course, $version, $actor, $courseData, $versionData, $modules, $expectedRevision, $moduleRevisions): array {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            if ($authorized === null) {
                throw new LogicException('Only an active platform administrator can edit shared content.');
            }
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);
            $lockedVersion = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if (! $lockedCourse->is_shared || $lockedCourse->company_id !== null || $lockedVersion->course_id !== $lockedCourse->id || $lockedVersion->status !== CourseVersionStatus::Draft) {
                throw new LogicException('Only platform-owned shared course drafts can be saved.');
            }
            $compositions = CourseVersionModule::query()->where('course_version_id', $lockedVersion->id)->orderBy('position')->lockForUpdate()->get();
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
            $lockedModules = ModuleVersion::query()->whereIn('id', $compositions->pluck('lesson_id'))->lockForUpdate()->get()->keyBy('id');
            $prepared = [];
            foreach ($modules as $modulePayload) {
                $module = $lockedModules->get($modulePayload['id']);
                $prepared[] = $this->writer->prepare($module, $modulePayload, $moduleRevisions[$module->id] ?? '');
            }
            $lockedCourse->update(['code' => $normalizedCode, 'title' => trim($data['course']['title']), 'description' => $data['course']['description']]);
            $lockedVersion->update(['title' => trim($data['course']['title']), 'description' => $data['version']['description']]);
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
        $compositions ??= $version->moduleCompositions()->get();

        return hash('sha256', json_encode([
            'course' => $course->only(['id', 'code', 'title', 'description']),
            'version' => $version->only(['id', 'title', 'description']),
            'compositions' => $compositions->map(fn ($item): array => ['id' => $item->id, 'module_id' => $item->lesson_id, 'position' => $item->position, 'required' => (bool) $item->is_required])->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
