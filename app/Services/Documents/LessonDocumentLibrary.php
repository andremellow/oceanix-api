<?php

namespace App\Services\Documents;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class LessonDocumentLibrary
{
    public function __construct(private readonly LessonDocumentLibraryAccess $access) {}

    public function page(User|Account $actor, string $search = '', int $page = 1): array
    {
        Validator::make(compact('search', 'page'), ['search' => ['string', 'max:240'], 'page' => ['integer', 'min:1']])->validate();
        $actor = $this->access->authorizeActor($actor, 'view');
        $query = $this->access->ownedQuery($actor)->whereDoesntHave('archive');
        if ($search !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }
        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / 20));
        $page = min($page, $lastPage);
        $canReuse = $actor instanceof Account || Gate::forUser($actor)->allows(Permission::LessonDocumentsReuse->value);
        $canArchive = $actor instanceof Account || Gate::forUser($actor)->allows(Permission::LessonDocumentsArchive->value);
        $items = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, 20)->get()->map(fn ($document) => [
            'id' => $document->public_id, 'name' => $document->name, 'size_bytes' => $document->size_bytes,
            'open_url' => $actor instanceof User
                ? route('lesson-documents.library.open', ['company' => $actor->company, 'document' => $document->public_id])
                : route('platform.lesson-documents.library.open', ['document' => $document->public_id]),
            'can_reuse' => $canReuse, 'can_archive' => $canArchive,
        ])->all();

        return ['items' => $items, 'current_page' => $page, 'last_page' => $lastPage, 'total' => $total, 'per_page' => 20];
    }
}
