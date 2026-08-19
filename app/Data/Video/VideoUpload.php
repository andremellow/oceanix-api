<?php

namespace App\Data\Video;

/** A provider-side upload slot: where the admin uploads, and the asset it will become. */
class VideoUpload
{
    public function __construct(
        public readonly string $provider,
        public readonly string $assetId,
        public readonly string $uploadUrl,
        public readonly ?string $uploadId = null,
    ) {}
}
