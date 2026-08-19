<?php

namespace App\Data\Video;

use App\Enums\VideoStatus;

class VideoAssetStatus
{
    public function __construct(
        public readonly VideoStatus $status,
        public readonly ?string $playbackId = null,
        public readonly ?int $durationSeconds = null,
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {}
}
