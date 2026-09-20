<?php

namespace App\Actions\Documents;

use App\Models\Account;
use App\Models\LessonDocumentArchive;
use App\Models\User;
use App\Services\Documents\LessonDocumentLibraryAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ArchiveLessonDocument
{
    public function __construct(private readonly LessonDocumentLibraryAccess $access) {}

    public function handle(string $publicId, User|Account $actor): void
    {
        Validator::make(['pdf' => $publicId], ['pdf' => ['required', 'uuid']])->validate();
        $actor = $this->access->authorizeActor($actor, 'archive');
        DB::transaction(function () use ($actor, $publicId): void {
            $actor = $this->access->authorizeActor($actor, 'archive');
            $document = $this->access->ownedQuery($actor)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $actor = $this->access->authorizeActor($actor, 'archive');
            $this->access->authorizeDocument($actor, $document, 'archive');
            if (! $document->archive()->exists()) {
                LessonDocumentArchive::create([
                    'lesson_document_id' => $document->id,
                    'archived_by_user_id' => $actor instanceof User ? $actor->id : null,
                    'archived_by_account_id' => $actor instanceof Account ? $actor->id : null,
                    'archived_at' => now(),
                ]);
            }
        }, 3);
    }
}
