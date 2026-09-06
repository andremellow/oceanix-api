<?php

namespace App\Services\Courses;

use App\Contracts\VideoProvider;
use App\Services\Video\FakeVideoProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PreviewPlaybackService
{
    public function __construct(private readonly PublicPreviewResolver $resolver, private readonly VideoProvider $provider) {}

    public function authorize(#[\SensitiveParameter] string $token, string $kind, string $item): array
    {
        $link = $this->resolver->resolve($token);
        $video = $this->resolver->authoredVideo($this->resolver->item($link, $kind, $item));
        abort_unless($video?->isPlayable() && $video->provider === $this->provider->key(), 409);
        $expiry = Carbon::instance($link->expires_at)->min(now()->addSeconds(60))->startOfSecond();
        if ($this->provider instanceof FakeVideoProvider) {
            abort_unless(Storage::disk(FakeVideoProvider::DISK)->exists($this->provider->path($video->provider_asset_id)), 409);
            $url = URL::temporarySignedRoute('course-preview.local-media', $expiry, ['token' => $token, 'kind' => $kind, 'item' => $item, 'asset' => $video->id]);
            $poster = null;
        } else {
            $grant = $this->provider->createPlaybackAuthorization($video, 1, $expiry);
            abort_unless($grant->expiresAt->lessThanOrEqualTo($expiry), 503);
            $expiry = $grant->expiresAt;
            $url = $grant->playbackUrl;
            $poster = $grant->posterUrl;
        }
        // Provider latency can cross expiry, publication, composition edits or replacement.
        $current = $this->resolver->authoredVideo($this->resolver->item($this->resolver->resolve($token), $kind, $item));
        abort_unless($current?->isPlayable() && $current->id === $video->id && $current->provider_asset_id === $video->provider_asset_id && $current->provider === $video->provider, 409);
        abort_unless($expiry->isFuture(), 410);

        return ['playback_url' => $url, 'expires_at' => $expiry->toIso8601String(), 'poster_url' => $poster];
    }
}
