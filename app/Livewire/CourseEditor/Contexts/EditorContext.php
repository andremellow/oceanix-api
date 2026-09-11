<?php

namespace App\Livewire\CourseEditor\Contexts;

use App\Services\CourseEditor\EditorSaveCommand;
use App\Services\CourseEditor\EditorSaveResult;
use App\Services\CourseEditor\EditorSnapshot;

interface EditorContext
{
    public function name(): string;

    public function open(int $rootId): EditorSnapshot;

    public function save(int $rootId, EditorSaveCommand $command): EditorSaveResult;

    /** @param array<string, mixed> $payload */
    public function performStructure(int $rootId, string $operation, array $payload): EditorSnapshot;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function performMedia(int $rootId, string $operation, array $payload): array;

    /** @param array<string, mixed> $payload */
    public function publish(int $rootId, array $payload = []): void;

    /** @param array<string, mixed> $payload */
    public function discard(int $rootId, array $payload = []): void;

    /** @return array<string, list<array<string, mixed>>> */
    public function availableRecords(int $rootId, string $search = ''): array;

    /** @return list<array<string, mixed>> */
    public function videoLibrary(int $rootId, int $recordId, string $search = ''): array;

    /** @return array<string, int> */
    public function publicationImpact(int $rootId): array;

    /** @return list<string> */
    public function publicationProblems(int $rootId): array;

    /**
     * @return array{
     *     title: string,
     *     body: string,
     *     submit_label: string,
     *     problems_title: string,
     *     assignment_mode: bool,
     *     restart_in_progress: bool,
     *     restart_description: string
     * }
     */
    public function publicationConfirmation(int $rootId): array;
}
