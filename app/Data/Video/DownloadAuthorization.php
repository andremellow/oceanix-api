<?php

namespace App\Data\Video;

use Illuminate\Support\Carbon;

/**
 * Reserved for the future offline iPad packages. Not exposed in the MVD — protected
 * download is not DRM, and the offline flow ships only once it has its own validation.
 */
class DownloadAuthorization
{
    public function __construct(
        public readonly string $downloadUrl,
        public readonly Carbon $expiresAt,
        public readonly ?string $checksum = null,
    ) {}
}
