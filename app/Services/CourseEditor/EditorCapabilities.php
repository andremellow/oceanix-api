<?php

namespace App\Services\CourseEditor;

final readonly class EditorCapabilities
{
    /** @param array<string, string> $notApplicable */
    public function __construct(
        public bool $editCourseDetails,
        public bool $editContent,
        public bool $manageStructure,
        public bool $manageMedia,
        public bool $publish,
        public bool $discard,
        public array $notApplicable = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
