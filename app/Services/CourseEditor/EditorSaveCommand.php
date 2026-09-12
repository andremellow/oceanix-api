<?php

namespace App\Services\CourseEditor;

use Illuminate\Validation\ValidationException;

final readonly class EditorSaveCommand
{
    /**
     * @param  array<string, mixed>|null  $course
     * @param  array<string, mixed>  $version
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, string>  $expectedRevisions
     * @param  list<string>  $dirtyContentKeys
     */
    public function __construct(
        public ?array $course,
        public array $version,
        public array $records,
        public array $expectedRevisions,
        public int $localGeneration,
        public array $dirtyContentKeys = [],
    ) {
        $this->assertPersistedStructure();
    }

    public function expectedRevision(string $name = 'root'): string
    {
        $revision = $this->expectedRevisions[$name] ?? '';

        if ($revision === '') {
            throw ValidationException::withMessages(['revision' => __('The editor revision is missing. Reload the draft before saving.')]);
        }

        return $revision;
    }

    private function assertPersistedStructure(): void
    {
        foreach ($this->records as $recordIndex => $record) {
            $this->assertPersistedRecord($record, "records.{$recordIndex}");
            foreach ($record['questions'] ?? [] as $questionIndex => $question) {
                $this->assertPersistedRecord($question, "records.{$recordIndex}.questions.{$questionIndex}");
                foreach ($question['options'] ?? [] as $optionIndex => $option) {
                    $this->assertPersistedRecord($option, "records.{$recordIndex}.questions.{$questionIndex}.options.{$optionIndex}");
                }
            }
        }
    }

    /** @param array<string, mixed> $record */
    private function assertPersistedRecord(array $record, string $path): void
    {
        $id = $record['id'] ?? null;
        $key = (string) ($record['key'] ?? '');

        if (! is_int($id) || $id < 1 || str_starts_with($key, 'tmp:')) {
            throw ValidationException::withMessages([$path => __('Save or discard structural changes before saving authored fields.')]);
        }
    }
}
