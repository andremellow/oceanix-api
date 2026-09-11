<?php

namespace App\Services\Courses;

use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use Illuminate\Support\Collection;

final class CourseVersionComposition
{
    public const Empty = 'empty';

    public const DirectLessons = 'direct_lessons';

    public const Modules = 'modules';

    public const Mixed = 'mixed';

    /**
     * @return array{mode: string, directLessons: Collection<int, Lesson>, mirroredDirectRows: Collection<int, CourseVersionModule>, reusableRows: Collection<int, CourseVersionModule>}
     */
    public function inspect(CourseVersion $version): array
    {
        $directLessons = $version->lessons()->with(['video', 'questions.options'])->get();
        $directIds = $directLessons->pluck('id');
        $rows = $version->moduleCompositions()->with('moduleVersion')->get();
        $mirrored = $rows->whereIn('lesson_id', $directIds)->values();
        $reusable = $rows->whereNotIn('lesson_id', $directIds)->values();

        $mode = match (true) {
            $directLessons->isNotEmpty() && $reusable->isNotEmpty() => self::Mixed,
            $directLessons->isNotEmpty() => self::DirectLessons,
            $reusable->isNotEmpty() => self::Modules,
            default => self::Empty,
        };

        return compact('mode', 'directLessons') + [
            'mirroredDirectRows' => $mirrored,
            'reusableRows' => $reusable,
        ];
    }

    /** @return Collection<int, Lesson> */
    public function canonicalLessons(CourseVersion $version): Collection
    {
        $state = $this->inspect($version);

        return match ($state['mode']) {
            self::DirectLessons => $state['directLessons'],
            self::Modules => $state['reusableRows']->map->moduleVersion->filter()->values(),
            self::Empty => collect(),
            default => throw new \LogicException(__('ui.mixed_composition_error')),
        };
    }
}
