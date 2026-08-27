<?php

namespace App\Data\Video;

use App\Enums\VideoStatus;

class VideoLibraryItem
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $title,
        public readonly VideoStatus $status,
        public readonly ?int $durationSeconds = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $hlsUrl = null,
    ) {}
}
