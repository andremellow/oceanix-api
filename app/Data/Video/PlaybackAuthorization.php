<?php

namespace App\Data\Video;

use Illuminate\Support\Carbon;

/**
 * A short-lived grant to play one asset. Copying the URL does not grant lasting access:
 * the token expires and the backend remains the authority.
 */
class PlaybackAuthorization
{
    public function __construct(
        public readonly string $token,
        public readonly string $playbackUrl,
        public readonly Carbon $expiresAt,
        public readonly ?string $posterUrl = null,
    ) {}

    public function secondsRemaining(): int
    {
        return max(0, (int) now()->diffInSeconds($this->expiresAt, absolute: false));
    }
}
