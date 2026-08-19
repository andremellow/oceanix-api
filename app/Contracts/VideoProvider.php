<?php

namespace App\Contracts;

use App\Data\Video\DownloadAuthorization;
use App\Data\Video\PlaybackAuthorization;
use App\Data\Video\VideoAssetStatus;
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

    public function createUpload(string $title, int $maxDurationSeconds): VideoUpload;

    public function getAssetStatus(string $assetId): VideoAssetStatus;

    public function createPlaybackAuthorization(Video $video, int $ttlMinutes): PlaybackAuthorization;

    public function createDownloadAuthorization(Video $video, int $ttlMinutes): DownloadAuthorization;

    public function deleteAsset(string $assetId): void;
}
