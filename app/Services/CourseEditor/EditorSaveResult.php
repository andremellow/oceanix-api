<?php

namespace App\Services\CourseEditor;

final readonly class EditorSaveResult
{
    public function __construct(
        public EditorSnapshot $snapshot,
        public int $acknowledgedGeneration,
        public bool $changed,
    ) {}
}
