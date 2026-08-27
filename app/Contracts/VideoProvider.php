<?php

namespace App\Contracts;

use App\Data\Video\DownloadAuthorization;
use App\Data\Video\PlaybackAuthorization;
use App\Data\Video\VideoAssetStatus;
use App\Data\Video\VideoLibraryItem;
use App\Data\Video\VideoUpload;
use App\Models\Video;

/**
 * The training domain never talks to a video vendor directly. Tokens, signing keys and URL
 * shapes stay behind this contract, so Cloudflare Stream can be replaced (Mux, Vimeo)
 * without touching lessons, assignments or compliance events.
 * See docs/product-spec.md §12.
 */
interface VideoProvider
{
    public function key(): string;

    /**
     * Verify that this provider is usable with the current configuration.
     *
     * Meant for an operator running a check, not for the request path: it may perform
     * network calls. `$write` also exercises a mutating call, which is the only way to
     * prove the credentials carry write scope.
     *
     * @return list<array{label: string, ok: bool, detail: string|null}>
     */
    public function verifyConfiguration(bool $write = true): array;

    public function createUpload(string $title, int $maxDurationSeconds, string $ownerKey): VideoUpload;

    /** @return list<VideoLibraryItem> */
    public function listAssets(int $limit = 12, string $search = '', string $ownerKey = ''): array;

    public function createAssetPreviewAuthorization(string $assetId, ?string $hlsUrl, int $ttlMinutes): PlaybackAuthorization;

    public function getAssetStatus(string $assetId): VideoAssetStatus;

    public function createPlaybackAuthorization(Video $video, int $ttlMinutes): PlaybackAuthorization;

    public function createDownloadAuthorization(Video $video, int $ttlMinutes): DownloadAuthorization;

    public function deleteAsset(string $assetId): void;
}
