<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CoursePreviewLink extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['token_encrypted' => 'encrypted', 'generated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $link): void {
            if ($link->isDirty(['course_version_id', 'token_hash', 'token_encrypted', 'generated_at', 'expires_at'])) {
                throw new LogicException('Preview generations are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Preview history cannot be deleted.'));
    }

    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class);
    }
}
