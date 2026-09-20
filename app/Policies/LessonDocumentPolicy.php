<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\LessonDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class LessonDocumentPolicy
{
    private function allows(User $user, LessonDocument $document, Permission $permission): bool
    {
        return $user->status === UserStatus::Active && $user->company_id > 0
            && ! $document->is_shared && (int) $document->company_id === (int) $user->company_id
            && Gate::forUser($user)->allows(Permission::LessonDocumentsView->value)
            && Gate::forUser($user)->allows($permission->value);
    }

    public function view(User $user, LessonDocument $document): bool
    {
        return $this->allows($user, $document, Permission::LessonDocumentsView);
    }

    public function reuse(User $user, LessonDocument $document): bool
    {
        return $this->allows($user, $document, Permission::LessonDocumentsReuse);
    }

    public function archive(User $user, LessonDocument $document): bool
    {
        return $this->allows($user, $document, Permission::LessonDocumentsArchive);
    }
}
