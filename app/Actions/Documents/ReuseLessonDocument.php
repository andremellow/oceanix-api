<?php

namespace App\Actions\Documents;

use App\Models\Account;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CourseEditor\EditorSnapshotBuilder;
use App\Services\Documents\LessonDocumentLibraryAccess;
use App\Services\Modules\ModuleLineageLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReuseLessonDocument
{
    public function __construct(private readonly EditorSnapshotBuilder $snapshots, private readonly LessonDocumentLibraryAccess $access) {}

    public function handle(string $publicId, string $context, int $rootId, int $recordId, User|Account $actor, string $revision): array
    {
        Validator::make(['pdf' => $publicId], ['pdf' => ['required', 'uuid']])->validate();
        $actor = $this->access->authorizeActor($actor, 'reuse');
        $this->resolve($context, $rootId, $recordId, $actor);

        return DB::transaction(function () use ($publicId, $context, $rootId, $recordId, $actor, $revision): array {
            if ($context !== 'shared-module') {
                $root = Course::withoutGlobalScopes()->lockForUpdate()->findOrFail($rootId);
                $versions = $root->versions()->orderBy('id')->lockForUpdate()->get();
                if ($context === 'shared-course') {
                    $versions->each(fn ($version) => $version->moduleCompositions()->orderBy('id')->lockForUpdate()->get());
                }
            }
            if ($context === 'company-course') {
                Lesson::query()->lockForUpdate()->findOrFail($recordId);
            } else {
                app(ModuleLineageLock::class)->versions([$recordId]);
            }
            $actor = $this->access->authorizeActor($actor, 'reuse');
            $this->resolve($context, $rootId, $recordId, $actor);
            $snapshot = match ($context) {
                'company-course' => $this->snapshots->forCompanyCourse($rootId, $actor),
                'shared-course' => $this->snapshots->forSharedCourse($rootId, $actor),
                'shared-module' => $this->snapshots->forSharedModule($rootId, $actor),
            };
            $current = $context === 'shared-course' ? $snapshot->revisions['record:'.$recordId] : $snapshot->revisions['root'];
            if (! hash_equals($current, $revision)) {
                throw ValidationException::withMessages(['pdf' => __('This lesson changed. Close this dialog and try again.')]);
            }
            // Never put the archive absence predicate on the locking query: it may wait.
            $document = $this->access->ownedQuery($actor)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $actor = $this->access->authorizeActor($actor, 'reuse');
            $this->access->authorizeDocument($actor, $document, 'reuse');
            $lesson = $this->resolve($context, $rootId, $recordId, $actor);
            if ($document->archive()->exists()) {
                throw ValidationException::withMessages(['pdf' => __('This PDF is no longer available for reuse.')]);
            }
            $lesson->documents()->syncWithoutDetaching([$document->id]);

            return ['id' => $document->public_id, 'name' => $document->name, 'reference' => '/lesson-documents/'.$document->public_id];
        }, 3);
    }

    private function resolve(string $context, int $rootId, int $recordId, User|Account $actor): Lesson
    {
        abort_unless(in_array($context, ['company-course', 'shared-course', 'shared-module'], true), 404);
        abort_unless(($context === 'company-course') === ($actor instanceof User), 403);
        $lesson = match ($context) {
            'company-course' => $this->snapshots->companyRecord($rootId, $recordId, $actor),
            'shared-course' => $this->snapshots->sharedCourseRecord($rootId, $recordId, $actor),
            'shared-module' => $this->snapshots->sharedModuleVersion($rootId, $actor),
        };
        abort_unless($lesson->id === $recordId, 404);
        abort_unless($lesson->getRawOriginal('status') === 'draft', 409);
        abort_unless($actor instanceof User
            ? (! $lesson->is_shared && (int) $lesson->company_id === (int) $actor->company_id)
            : ($lesson->is_shared && $lesson->company_id === null), 404);

        return $lesson;
    }
}
