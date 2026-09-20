<?php

namespace App\Actions\Documents;

use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonDocument;
use App\Models\User;
use App\Services\CourseEditor\EditorSnapshotBuilder;
use App\Services\Modules\ModuleLineageLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UploadLessonDocument
{
    public function __construct(private readonly EditorSnapshotBuilder $snapshots) {}

    public function handle(UploadedFile $upload, string $context, int $rootId, int $recordId, User|Account $actor, string $revision): array
    {
        Validator::make(['pdf' => $upload], ['pdf' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240']])->validate();
        $this->resolve($context, $rootId, $recordId, $actor);
        $path = $upload->store('', 'lesson_documents');
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('PDF storage failed.');
        }
        try {
            $document = DB::transaction(function () use ($upload, $context, $rootId, $recordId, $actor, $revision, $path): LessonDocument {
                if ($context !== 'shared-module') {
                    $root = Course::withoutGlobalScopes()->lockForUpdate()->findOrFail($rootId);
                    $root->versions()->orderBy('id')->lockForUpdate()->get();
                    if ($context === 'shared-course') {
                        $root->versions()->each(fn ($version) => $version->moduleCompositions()->orderBy('id')->lockForUpdate()->get());
                    }
                }
                if ($context === 'company-course') {
                    Lesson::query()->lockForUpdate()->findOrFail($recordId);
                } else {
                    app(ModuleLineageLock::class)->versions([$recordId]);
                }
                $lesson = $this->resolve($context, $rootId, $recordId, $actor->fresh());
                $snapshot = match ($context) {
                    'company-course' => $this->snapshots->forCompanyCourse($rootId, $actor->fresh()),
                    'shared-course' => $this->snapshots->forSharedCourse($rootId, $actor->fresh()),
                    'shared-module' => $this->snapshots->forSharedModule($rootId, $actor->fresh()),
                };
                $current = $context === 'shared-course' ? $snapshot->revisions['record:'.$recordId] : $snapshot->revisions['root'];
                if (! hash_equals($current, $revision)) {
                    throw ValidationException::withMessages(['pdf' => __('This draft changed in another session. Your unsaved changes are still here.')]);
                }
                $document = LessonDocument::query()->create([
                    'public_id' => (string) Str::uuid(), 'company_id' => $lesson->company_id,
                    'is_shared' => (bool) $lesson->is_shared,
                    'name' => Str::limit(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $upload->getClientOriginalName()))), 240, ''),
                    'disk' => 'lesson_documents', 'path' => $path,
                    'mime_type' => 'application/pdf', 'size_bytes' => $upload->getSize(),
                ]);
                $lesson->documents()->attach($document);

                return $document;
            });
        } catch (Throwable $exception) {
            Storage::disk('lesson_documents')->delete($path);
            throw $exception;
        }

        return ['id' => $document->public_id, 'name' => $document->name, 'reference' => '/lesson-documents/'.$document->public_id];
    }

    private function resolve(string $context, int $rootId, int $recordId, User|Account $actor): Lesson
    {
        $actor = $actor->fresh();
        abort_unless($actor instanceof User ? $actor->status === UserStatus::Active : ($actor->status === 'active' && $actor->is_platform_admin), 403);
        $lesson = match ($context) {
            'company-course' => $this->snapshots->companyRecord($rootId, $recordId, $actor),
            'shared-course' => $this->snapshots->sharedCourseRecord($rootId, $recordId, $actor),
            'shared-module' => $this->snapshots->sharedModuleVersion($rootId, $actor),
            default => abort(404),
        };
        abort_unless($lesson->id === $recordId, 404);
        abort_unless($lesson->getRawOriginal('status') === 'draft', 409);
        if ($actor instanceof User) {
            abort_unless(! $lesson->is_shared && (int) $lesson->company_id === (int) $actor->company_id, 403);
        }

        return $lesson;
    }
}
