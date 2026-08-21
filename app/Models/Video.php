<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Only stable provider identifiers are persisted. Playback authorization is minted per
 * request by the VideoProvider, so copying a URL never grants lasting access.
 */
#[Fillable([
    'lesson_id', 'provider', 'provider_asset_id', 'provider_playback_id',
    'duration_seconds', 'status', 'metadata',
])]
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'metadata' => 'array',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function isPlayable(): bool
    {
        return $this->status === VideoStatus::Ready;
    }

    public function formattedDuration(): string
    {
        if ($this->duration_seconds === null) {
            return '—';
        }

        return sprintf('%d:%02d', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60);
    }
}
