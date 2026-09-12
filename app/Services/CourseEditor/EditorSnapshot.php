<?php

namespace App\Services\CourseEditor;

final readonly class EditorSnapshot
{
    /**
     * @param  array<string, mixed>|null  $course
     * @param  array<string, mixed>  $version
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, string>  $revisions
     */
    public function __construct(
        public string $context,
        public int $rootId,
        public ?array $course,
        public array $version,
        public array $records,
        public array $revisions,
        public EditorCapabilities $capabilities,
        public string $composition = 'direct',
        public string $closeUrl = '',
        public array $preservedRecords = [],
        public ?array $previewPanel = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'context' => $this->context,
            'root_id' => $this->rootId,
            'course' => $this->course,
            'version' => $this->version,
            'records' => $this->records,
            'revisions' => $this->revisions,
            'capabilities' => $this->capabilities->toArray(),
            'composition' => $this->composition,
            'close_url' => $this->closeUrl,
            'preserved_records' => $this->preservedRecords,
            'preview_panel' => $this->previewPanel,
        ];
    }
}
