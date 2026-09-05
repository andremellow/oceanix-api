<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\ModuleVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Modules\ModuleLineageLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class DiscardSharedCourseDraft
{
    public function __construct(private readonly AuditLogger $audit, private readonly ModuleLineageLock $lineageLock) {}

    public function handle(CourseVersion $version, Account $actor, string $reason, string $expectedRevision): CourseVersion
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['discardReason' => __('A reason is required.')]);
        }

        return DB::transaction(function () use ($version, $actor, $reason, $expectedRevision): CourseVersion {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            $courseId = CourseVersion::query()->whereKey($version->id)->firstOrFail(['course_id'])->course_id;
            $course = Course::query()->lockForUpdate()->findOrFail($courseId);
            $locked = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($authorized === null || ! $course->is_shared || $course->company_id !== null || $course->status === CourseStatus::Archived || $locked->status !== CourseVersionStatus::Draft || $locked->publication_kind !== 'manual') {
                throw new LogicException('Only manual platform-owned shared drafts can be discarded.');
            }
            if ((int) $locked->course_id !== (int) $course->id) {
                throw new LogicException('The draft changed courses while it was being locked.');
            }
            if (! hash_equals($this->revision($locked), $expectedRevision)) {
                throw ValidationException::withMessages(['discard' => __('This draft changed elsewhere. Reload the page before trying again.')]);
            }

            $compositions = $locked->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $moduleIds = $compositions->pluck('lesson_id')->filter()->map(fn ($id): int => (int) $id);
            $drafts = ModuleVersion::query()->whereIn('id', $moduleIds)->get()->keyBy('id');
            $sourceIds = $drafts->filter(fn (ModuleVersion $module): bool => $module->status === ModuleVersionStatus::Draft)
                ->pluck('source_lesson_id')->filter()->map(fn ($id): int => (int) $id);
            $requestedIds = $moduleIds->merge($sourceIds)->unique()->sort()->values();
            $modules = $this->lineageLock->versions($requestedIds)->whereIn('id', $requestedIds)->keyBy('id');
            if ($modules->count() !== $requestedIds->count() || $modules->contains(fn (ModuleVersion $module): bool => ! $module->is_shared
                || $module->company_id !== null
                || $module->lineage_archived_at !== null)) {
                throw new LogicException('The draft contains an unavailable shared module lineage.');
            }

            $beforeComposition = $compositions->map(fn ($row): array => [
                'composition_id' => $row->id,
                'module_version_id' => $row->lesson_id,
                'position' => $row->position,
                'is_required' => (bool) $row->is_required,
            ])->values()->all();

            foreach ($compositions as $composition) {
                $module = $modules->get($composition->lesson_id);
                if ($module?->status !== ModuleVersionStatus::Draft) {
                    continue;
                }

                $source = $module->source_lesson_id === null ? null : $modules->get($module->source_lesson_id);
                $validSource = $source !== null
                    && in_array($source->status, [ModuleVersionStatus::Published, ModuleVersionStatus::Retired], true)
                    && $source->is_shared
                    && $source->company_id === null
                    && $source->lineage_uuid === $module->lineage_uuid;

                if ($validSource) {
                    $composition->update(['lesson_id' => $source->id]);
                } elseif ($module->source_lesson_id === null) {
                    $composition->delete();
                } else {
                    throw new LogicException('A draft module has an invalid immutable source and cannot be discarded safely.');
                }
            }

            $afterComposition = $locked->moduleCompositions()->get()->map(fn ($row): array => [
                'composition_id' => $row->id,
                'module_version_id' => $row->lesson_id,
                'position' => $row->position,
                'is_required' => (bool) $row->is_required,
            ])->values()->all();
            $locked->update(['status' => CourseVersionStatus::Discarded]);
            $this->audit->log(
                'shared_course.draft_discarded',
                $locked,
                before: ['status' => 'draft', 'composition' => $beforeComposition],
                after: ['status' => 'discarded', 'reason' => $reason, 'composition' => $afterComposition],
                platformActor: $authorized,
            );

            return $locked->refresh();
        });
    }

    public function revision(CourseVersion $version): string
    {
        $compositions = $version->moduleCompositions()->get()
            ->map(fn ($row): array => [$row->id, $row->lesson_id, $row->position, (bool) $row->is_required])
            ->all();

        return hash('sha256', json_encode([
            $version->id,
            $version->getRawOriginal('status'),
            $version->updated_at?->format('Y-m-d H:i:s.u'),
            $compositions,
        ], JSON_THROW_ON_ERROR));
    }
}
