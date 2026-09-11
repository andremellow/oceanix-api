<?php

namespace Tests\Support\CourseEditor;

final readonly class EditorContextCase
{
    /**
     * @param  list<string>  $applicableActions
     * @param  array<string, string>  $notApplicable
     */
    private function __construct(
        public string $name,
        public string $component,
        public string $route,
        public array $applicableActions,
        public array $notApplicable,
    ) {}

    public static function companyCourse(): self
    {
        return new self(
            'company-course',
            'courses.editor',
            'courses.editor',
            ['save', 'add', 'remove', 'move-up', 'move-down', 'reorder', 'change-composition', 'upload', 'open-library', 'attach', 'replace', 'remove-media'],
            [],
        );
    }

    public static function sharedCourse(): self
    {
        return new self(
            'shared-course',
            'platform.shared-courses.editor',
            'platform.shared-courses.editor',
            ['save', 'add', 'remove', 'move-up', 'move-down', 'reorder', 'change-composition', 'upload', 'open-library', 'attach', 'replace', 'remove-media'],
            [],
        );
    }

    public static function sharedModule(): self
    {
        return new self(
            'shared-module',
            'platform.shared-modules.editor',
            'platform.shared-modules.editor',
            ['save', 'add', 'remove', 'move-up', 'move-down', 'reorder', 'upload', 'open-library', 'attach', 'replace', 'remove-media'],
            ['change-composition' => 'A standalone module has no course composition.'],
        );
    }

    /** @return array<string, self> */
    public static function all(): array
    {
        return [
            'company course' => self::companyCourse(),
            'shared course' => self::sharedCourse(),
            'standalone shared module' => self::sharedModule(),
        ];
    }
}
