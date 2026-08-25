<?php

namespace App\Services\Video;

use App\Contracts\VideoProvider;
use App\Data\Video\DownloadAuthorization;
use App\Data\Video\PlaybackAuthorization;
use App\Data\Video\VideoAssetStatus;
use App\Data\Video\VideoLibraryItem;
use App\Data\Video\VideoUpload;
use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Local development stand-in for a real video provider.
 *
 * It exists so the course editor, the publication rules and later the player can be
 * exercised before Cloudflare credentials are contracted. Files land on the local disk and
 * playback is served through a signed, expiring URL — the same shape the real provider
 * returns, so nothing downstream can accidentally depend on provider specifics.
 *
 * It is bound only when the application is local AND Cloudflare is unconfigured; see
 * AppServiceProvider. It is never a production path.
 */
class FakeVideoProvider implements VideoProvider
{
    public const DISK = 'local';

    public function key(): string
    {
        return 'local_fake';
    }

    /** @return list<array{label: string, ok: bool, detail: string|null}> */
    public function verifyConfiguration(bool $write = true): array
    {
        return [[
            'label' => 'Local development provider',
            'ok' => true,
            'detail' => 'Files are stored on the local disk. Cloudflare is not being contacted.',
        ]];
    }

    public function createUpload(string $title, int $maxDurationSeconds): VideoUpload
    {
        $assetId = (string) Str::uuid();

        return new VideoUpload(
            provider: $this->key(),
            assetId: $assetId,
            uploadUrl: URL::temporarySignedRoute('dev.video.upload', now()->addHour(), ['asset' => $assetId]),
        );
    }

    /** @return list<VideoLibraryItem> */
    public function listAssets(int $limit = 12, string $search = ''): array
    {
        return [];
    }

    public function createAssetPreviewAuthorization(string $assetId, ?string $hlsUrl, int $ttlMinutes): PlaybackAuthorization
    {
        $expiresAt = now()->addMinutes($ttlMinutes);

        return new PlaybackAuthorization(
            token: 'local-fake',
            playbackUrl: URL::temporarySignedRoute('dev.video.play', $expiresAt, ['asset' => $assetId]),
            expiresAt: $expiresAt,
        );
    }

    public function getAssetStatus(string $assetId): VideoAssetStatus
    {
        $path = $this->path($assetId);

        if (! Storage::disk(self::DISK)->exists($path)) {
            return new VideoAssetStatus(VideoStatus::Uploading);
        }

        return new VideoAssetStatus(
            status: VideoStatus::Ready,
            playbackId: $assetId,
            // There is no encoder here, so approximate a duration from the file size just to
            // keep the interface honest. Never treat this as real metadata.
            durationSeconds: max(30, (int) round(Storage::disk(self::DISK)->size($path) / 120_000)),
            metadata: ['fake' => true],
        );
    }

    public function createPlaybackAuthorization(Video $video, int $ttlMinutes): PlaybackAuthorization
    {
        $expiresAt = now()->addMinutes($ttlMinutes);

        return new PlaybackAuthorization(
            token: 'local-fake',
            playbackUrl: URL::temporarySignedRoute('dev.video.play', $expiresAt, ['asset' => $video->provider_asset_id]),
            expiresAt: $expiresAt,
        );
    }

    public function createDownloadAuthorization(Video $video, int $ttlMinutes): DownloadAuthorization
    {
        $authorization = $this->createPlaybackAuthorization($video, $ttlMinutes);

        return new DownloadAuthorization(
            downloadUrl: $authorization->playbackUrl,
            expiresAt: $authorization->expiresAt,
        );
    }

    public function deleteAsset(string $assetId): void
    {
        Storage::disk(self::DISK)->delete($this->path($assetId));
    }

    public function path(string $assetId): string
    {
        return 'dev-videos/'.$assetId;
    }
}
