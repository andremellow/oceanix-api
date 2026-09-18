<?php

namespace App\Services\Documents;

use App\Enums\Permission;
use App\Enums\PlatformPermission;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\LessonDocument;
use App\Models\User;
use App\Services\Platform\PlatformAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LessonDocumentLibraryAccess
{
    public function authorizeActor(User|Account $actor, string $ability): User|Account
    {
        abort_unless(in_array($ability, ['view', 'reuse', 'archive'], true), 403);
        $actor = $actor->fresh();
        abort_unless($actor !== null, 403);
        if ($actor instanceof User) {
            abort_unless($actor->status === UserStatus::Active && $actor->company_id > 0, 403);
            Gate::forUser($actor)->authorize(Permission::LessonDocumentsView->value);
            Gate::forUser($actor)->authorize('lesson-documents.'.$ability);
        } else {
            $current = app(PlatformAccess::class)->authorizePermission(PlatformPermission::from('lesson-documents.'.$ability));
            abort_unless($current->id === $actor->id && $actor->status === 'active' && $actor->is_platform_admin, 403);
        }

        return $actor;
    }

    public function ownedQuery(User|Account $freshActor): Builder
    {
        if ($freshActor instanceof User) {
            abort_unless($freshActor->company_id > 0, 403);

            return LessonDocument::query()->where('company_id', $freshActor->company_id)->where('is_shared', false);
        }

        return LessonDocument::query()->whereNull('company_id')->where('is_shared', true);
    }

    public function authorizeDocument(User|Account $actor, LessonDocument $document, string $ability): void
    {
        $actor = $this->authorizeActor($actor, $ability);
        abort_unless($this->ownedQuery($actor)->whereKey($document->id)->exists(), 404);
        if ($actor instanceof User) {
            Gate::forUser($actor)->authorize($ability, $document);
        }
    }

    public function open(User|Account $actor, string $publicId): StreamedResponse
    {
        $actor = $this->authorizeActor($actor, 'view');
        $document = $this->ownedQuery($actor)->where('public_id', $publicId)->firstOrFail();
        $this->authorizeDocument($actor, $document, 'view');
        abort_if($document->archive()->exists(), 404);
        $stream = rescue(fn () => Storage::disk($document->disk)->readStream($document->path), false);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $document->name, 'document.pdf'),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
