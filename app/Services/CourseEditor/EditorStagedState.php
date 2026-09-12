<?php

namespace App\Services\CourseEditor;

final readonly class EditorStagedState
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $positions
     * @param  array<string, list<string>>  $validationMessages
     * @param  list<string>  $dirtyContentKeys
     */
    public function __construct(
        public array $values,
        public array $positions,
        public array $validationMessages,
        public array $dirtyContentKeys,
        public int $generation,
        public bool $dirty,
        public string $saveState,
        public ?string $errorKind,
        public ?string $saveError,
        public ?string $focusInvalidStableField,
    ) {}
}
