<?php

namespace App\Actions\Courses;

use App\Actions\Assignments\ReplaceAssignmentsForPublication;
use App\Enums\CourseVersionStatus;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\SharedContentPropagationItem;
use App\Services\Courses\CourseVersionValidator;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreatePropagatedCourseVersion
{
    public function __construct(
        private readonly CourseVersionValidator $validator,
        private readonly ReplaceAssignmentsForPublication $replaceAssignments,
    ) {}

    public function handle(SharedContentPropagationItem $item): CourseVersion
    {
        if ($item->result_course_version_id !== null) {
            return CourseVersion::query()->findOrFail($item->result_course_version_id);
        }

        return DB::transaction(function () use ($item): CourseVersion {
            $item = SharedContentPropagationItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->result_course_version_id !== null) {
                return CourseVersion::query()->findOrFail($item->result_course_version_id);
            }

            $course = Course::query()->lockForUpdate()->findOrFail($item->course_id);
            $source = $course->currentPublishedVersion;
            if ($source === null) {
                throw new LogicException('The course has no current published version.');
            }

            $targetModuleVersion = $item->propagation->moduleVersion;
            $targetLineage = $targetModuleVersion->lineage_uuid;
            $compositions = $source->moduleCompositions()->with('moduleVersion')->get();
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
                'published_by_account_id' => $item->propagation->initiated_by_account_id,
            ]);
            $source->update(['status' => CourseVersionStatus::Retired]);
            $course->update(['current_published_version_id' => $version->id]);
            $item->update(['result_course_version_id' => $version->id]);

            $this->replaceAssignments->handle(
                $source,
                $version,
                $item->propagation->initiator,
                $item->propagation->restart_in_progress,
                $item->propagation,
            );

            return $version->refresh();
        });
    }
}
