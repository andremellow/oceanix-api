<?php

namespace App\Actions\Courses;

use App\Actions\Modules\CreateModuleDraft;
use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\ModuleVersion;
use App\Services\Modules\ModuleContentSnapshot;
use App\Services\Modules\ModuleLineageLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class PrepareSharedCourseEditor
{
    public function __construct(private readonly CreateModuleDraft $createModuleDraft, private readonly ?ModuleLineageLock $lineageLock = null) {}

    public function handle(Course $course, Account $actor, string $expectedRevision): CourseVersion
    {
        return DB::transaction(function () use ($course, $actor, $expectedRevision): CourseVersion {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            $course = Course::query()->lockForUpdate()->findOrFail($course->id);
            $version = $course->versions()->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->lockForUpdate()->firstOrFail();
            if ($authorized === null || ! $course->is_shared || $course->company_id !== null || $course->status === CourseStatus::Archived) {
                throw new LogicException('Only an active platform administrator can prepare a manual shared-course draft.');
            }

            $compositions = $version->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->revisionFrom($course, $version, $compositions), $expectedRevision)) {
                throw ValidationException::withMessages(['editor' => __('This draft changed elsewhere. Reload the page before trying again.')]);
            }
            $modules = ($this->lineageLock ?? app(ModuleLineageLock::class))->versions($compositions->pluck('lesson_id'));
            if ($modules->isNotEmpty()) {
                $modules->load(['video', 'questions.options']);
            }
            $byId = $modules->keyBy('id');

            foreach ($compositions as $composition) {
                $source = $byId->get($composition->lesson_id);
                if ($source === null) {
                    throw new LogicException('The draft contains an unavailable module.');
                }
                if ($source->status === ModuleVersionStatus::Draft) {
                    continue;
                }
                $draft = $modules->first(fn (ModuleVersion $candidate): bool => $candidate->lineage_uuid === $source->lineage_uuid && $candidate->status === ModuleVersionStatus::Draft);
                if ($draft !== null && ! app(ModuleContentSnapshot::class)->matches($source, $draft)) {
                    throw ValidationException::withMessages(['editor' => __('A module already has a draft with different content. Resolve that module draft before opening this course draft. Existing work has been preserved.')]);
                }
                $draft ??= $this->createModuleDraft->handle($source, $authorized);
                $composition->update(['lesson_id' => $draft->id]);
            }

            return $version->refresh();
        }, 3);
    }

    public function revision(Course $course, CourseVersion $version): string
    {
        return $this->revisionFrom($course, $version, $version->moduleCompositions()->get());
    }

    private function revisionFrom(Course $course, CourseVersion $version, $compositions): string
    {
        return hash('sha256', json_encode([
            $course->id, $course->updated_at?->format('Y-m-d H:i:s.u'), $version->id,
            $version->getRawOriginal('status'), $version->publication_kind, $version->updated_at?->format('Y-m-d H:i:s.u'),
            $compositions->map(fn ($row): array => [$row->id, $row->lesson_id, $row->position, (bool) $row->is_required])->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
