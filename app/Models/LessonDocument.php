<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['public_id', 'company_id', 'is_shared', 'name', 'disk', 'path', 'mime_type', 'size_bytes'])]
class LessonDocument extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lesson documents are immutable.'));
        static::deleting(fn () => throw new LogicException('Lesson documents are retained.'));
    }
}
