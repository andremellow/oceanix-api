<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'company_id', 'is_shared', 'name', 'disk', 'path', 'mime_type', 'size_bytes'])]
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
        return Storage::disk($this->disk)->url($this->path);
    }
}
