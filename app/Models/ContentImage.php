<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'company_id', 'is_shared', 'name', 'path', 'mime_type', 'size_bytes'])]
class ContentImage extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (ContentImage $image) => $image->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_shared' => 'boolean', 'size_bytes' => 'integer'];
    }

    public function url(): string
    {
        return '/storage/'.ltrim($this->path, '/');
    }
}
