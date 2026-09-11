<?php

namespace App\Actions\Courses;

use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ReorderDirectCourseContent
{
    /** @param list<int> $orderedIds */
    public function handle(CourseVersion $version, User $actor, string $level, ?int $parentId, array $orderedIds): void
    {
        $ids = array_values(array_map('intval', $orderedIds));
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['order' => __('ui.stale_order')]);
        }

        DB::transaction(function () use ($version, $actor, $level, $parentId, $ids): void {
            $version = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            Gate::forUser($actor)->authorize('updateVersion', $version);
            $siblings = $this->siblings($version, $level, $parentId);
            if ($siblings->pluck('id')->sort()->values()->all() !== collect($ids)->sort()->values()->all()) {
                throw ValidationException::withMessages(['order' => __('ui.stale_order')]);
            }

            if ($level === 'lessons') {
                $version->moduleCompositions()->whereIn('lesson_id', $ids)->lockForUpdate()->get();
                foreach ($ids as $index => $id) {
                    $temporary = 1000000 + $index;
                    $version->moduleCompositions()->where('lesson_id', $id)->update(['position' => $temporary]);
                }
            }

            foreach ($ids as $index => $id) {
                $siblings->firstWhere('id', $id)->update(['position' => 1000000 + $index]);
            }
            foreach ($ids as $index => $id) {
                $siblings->firstWhere('id', $id)->update(['position' => $index + 1]);
                if ($level === 'lessons') {
                    $version->moduleCompositions()->where('lesson_id', $id)->update(['position' => $index + 1]);
                }
            }
        });
    }

    /** @return Collection<int, Lesson|Question|QuestionOption> */
    private function siblings(CourseVersion $version, string $level, ?int $parentId): Collection
    {
        return match ($level) {
            'lessons' => $version->lessons()->orderBy('id')->lockForUpdate()->get(),
            'questions' => $version->lessons()->lockForUpdate()->findOrFail($parentId)->questions()->orderBy('id')->lockForUpdate()->get(),
            'options' => Question::query()->whereHas('lesson', fn ($q) => $q->where('course_version_id', $version->id))->lockForUpdate()->findOrFail($parentId)->options()->orderBy('id')->lockForUpdate()->get(),
            default => throw ValidationException::withMessages(['order' => __('ui.stale_order')]),
        };
    }
}
