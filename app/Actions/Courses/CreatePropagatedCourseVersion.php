<?php

namespace App\Actions\Courses;

use App\Actions\Assignments\ReplaceAssignmentsForPublication;
use App\Enums\CourseVersionStatus;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\SharedContentPropagationItem;
use App\Services\Courses\CourseVersionValidator;
use App\Services\Modules\ModuleLineageLock;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreatePropagatedCourseVersion
{
    public function __construct(
        private readonly CourseVersionValidator $validator,
        private readonly ReplaceAssignmentsForPublication $replaceAssignments,
        private readonly ModuleLineageLock $lineageLock,
    ) {}

    public function handle(SharedContentPropagationItem $item): CourseVersion
    {
        if ($item->result_course_version_id !== null) {
            return CourseVersion::query()->findOrFail($item->result_course_version_id);
        }

        return DB::transaction(function () use ($item): CourseVersion {
            $courseId = SharedContentPropagationItem::query()->whereKey($item->id)->firstOrFail(['course_id'])->course_id;
            $course = Course::query()->lockForUpdate()->findOrFail($courseId);
            $item = SharedContentPropagationItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->result_course_version_id !== null) {
                return CourseVersion::query()->findOrFail($item->result_course_version_id);
            }
            if ((int) $item->course_id !== (int) $course->id) {
                throw new LogicException('The propagation item changed courses while it was being locked.');
            }

            $source = CourseVersion::query()->lockForUpdate()->find($course->current_published_version_id);
            if ($source === null) {
                throw new LogicException('The course has no current published version.');
            }

            $propagation = $item->propagation()->lockForUpdate()->firstOrFail();
            $compositions = $source->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $requestedModuleIds = $compositions->pluck('lesson_id')->push($propagation->module_version_id)->unique()->sort()->values();
            $modules = $this->lineageLock->versions($requestedModuleIds)->whereIn('id', $requestedModuleIds)->keyBy('id');
            $targetModuleVersion = $modules->get($propagation->module_version_id) ?? throw new LogicException('The propagated module version is unavailable.');
            $targetLineage = $targetModuleVersion->lineage_uuid;
            $compositions->each(fn ($composition) => $composition->setRelation('moduleVersion', $modules->get($composition->lesson_id)));
            $matchingComposition = $compositions->first(
                fn (CourseVersionModule $composition) => $composition->moduleVersion->lineage_uuid === $targetLineage
            );
            if ($matchingComposition === null) {
                throw new LogicException('The current course version no longer needs this propagation.');
            }
            if ($matchingComposition->moduleVersion->version_number >= $targetModuleVersion->version_number) {
                return $source;
            }

            $version = CourseVersion::query()->create([
                'course_id' => $course->id,
                'version_number' => ((int) $course->versions()->max('version_number')) + 1,
                'status' => CourseVersionStatus::Draft,
                'title' => $source->title,
                'description' => $source->description,
                'completion_rule' => $source->completion_rule,
                'publication_kind' => 'shared_propagation',
                'source_course_version_id' => $source->id,
                'propagation_item_id' => $item->id,
            ]);

            foreach ($compositions as $composition) {
                CourseVersionModule::query()->create([
                    'course_version_id' => $version->id,
                    'module_version_id' => $composition->moduleVersion->lineage_uuid === $targetLineage
                        ? $targetModuleVersion->id : $composition->module_version_id,
                    'position' => $composition->position,
                    'is_required' => $composition->is_required,
                ]);
            }

            $problems = $this->validator->problems($version);
            if ($problems !== []) {
                throw new LogicException(implode(' ', $problems));
            }

            $version->update([
                'status' => CourseVersionStatus::Published,
                'published_at' => now(),
                'published_by_account_id' => $propagation->initiated_by_account_id,
            ]);
            $source->update(['status' => CourseVersionStatus::Retired]);
            $course->update(['current_published_version_id' => $version->id]);
            $item->update(['result_course_version_id' => $version->id]);

            $this->replaceAssignments->handle(
                $source,
                $version,
                $propagation->initiator,
                $propagation->restart_in_progress,
                $propagation,
            );

            return $version->refresh();
        });
    }
}
