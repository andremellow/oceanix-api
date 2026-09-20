<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['lesson_document_id', 'archived_by_user_id', 'archived_by_account_id', 'archived_at'])]
class LessonDocumentArchive extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $archive): void {
            if (($archive->archived_by_user_id === null) === ($archive->archived_by_account_id === null)) {
                throw new LogicException('An archive requires exactly one actor.');
            }
        });
        static::updating(fn () => throw new LogicException('Document archives are immutable.'));
        static::deleting(fn () => throw new LogicException('Document archives are retained.'));
    }
}
