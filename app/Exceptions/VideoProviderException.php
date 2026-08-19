<?php

namespace App\Exceptions;

use RuntimeException;

class VideoProviderException extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self("Video provider [{$provider}] is not configured.");
    }

    public static function requestFailed(string $provider, string $operation): self
    {
        return new self("Video provider [{$provider}] failed during [{$operation}].");
    }

    public static function notPlayable(): self
    {
        return new self(__('This video is still being processed. Try again in a few minutes.'));
    }
}
