<?php

namespace App\Services\CourseEditor;

final readonly class EditorRebaseResult
{
    /**
     * @param  array<string, mixed>|null  $course
     * @param  array<string, mixed>  $version
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, list<string>>  $validationMessages
     * @param  list<string>  $dirtyContentKeys
     * @param  list<string>  $droppedRecordKeys
     */
    public function __construct(
        public ?array $course,
        public array $version,
        public array $records,
        public array $validationMessages,
        public array $dirtyContentKeys,
        public int $generation,
        public bool $dirty,
        public string $saveState,
        public ?string $errorKind,
        public ?string $saveError,
        public ?string $focusInvalidField,
        public array $droppedRecordKeys,
    ) {}
}
