<?php

namespace App\Services\Video;

use App\Contracts\VideoProvider;
use App\Data\Video\DownloadAuthorization;
use App\Data\Video\PlaybackAuthorization;
use App\Data\Video\VideoAssetStatus;
use App\Data\Video\VideoUpload;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Models\Video;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Stream implementation — ingestion, encoding, CDN and signed playback.
 *
 * Playback is authorized per request with a short-lived signed token, so the backend stays
 * the authority over who may watch a lesson. No permanent public URL is ever persisted.
 */
class CloudflareStreamProvider implements VideoProvider
{
    public function key(): string
    {
        return 'cloudflare_stream';
    }

    public function createUpload(string $title, int $maxDurationSeconds): VideoUpload
    {
        $response = $this->request()->post($this->accountUrl('/stream/direct_upload'), [
            'maxDurationSeconds' => $maxDurationSeconds,
            'requireSignedURLs' => true,
            'meta' => ['name' => $title],
        ]);

        $this->guard($response->successful(), 'createUpload', $response->json());

        return new VideoUpload(
            provider: $this->key(),
            assetId: (string) $response->json('result.uid'),
            uploadUrl: (string) $response->json('result.uploadURL'),
        );
    }

    public function getAssetStatus(string $assetId): VideoAssetStatus
    {
        $response = $this->request()->get($this->accountUrl("/stream/{$assetId}"));

        $this->guard($response->successful(), 'getAssetStatus', $response->json());

        $state = (string) $response->json('result.status.state');
        $duration = $response->json('result.duration');

        return new VideoAssetStatus(
            status: match ($state) {
                'ready' => VideoStatus::Ready,
                'inprogress', 'queued', 'downloading' => VideoStatus::Processing,
                'error' => VideoStatus::Failed,
                default => VideoStatus::Uploading,
            },
            playbackId: $response->json('result.uid'),
            durationSeconds: is_numeric($duration) ? (int) round((float) $duration) : null,
            metadata: [
                'thumbnail' => $response->json('result.thumbnail'),
                'state' => $state,
            ],
        );
    }

    public function createPlaybackAuthorization(Video $video, int $ttlMinutes): PlaybackAuthorization
    {
        $expiresAt = now()->addMinutes($ttlMinutes);

        $response = $this->request()->post(
            $this->accountUrl("/stream/{$video->provider_asset_id}/token"),
            ['exp' => $expiresAt->getTimestamp()],
        );

        $this->guard($response->successful(), 'createPlaybackAuthorization', $response->json());

        $token = (string) $response->json('result.token');

        return new PlaybackAuthorization(
            token: $token,
            playbackUrl: sprintf('%s/%s/manifest/video.m3u8', $this->deliveryHost(), $token),
            expiresAt: $expiresAt,
            posterUrl: sprintf('%s/%s/thumbnails/thumbnail.jpg', $this->deliveryHost(), $token),
        );
    }

    public function createDownloadAuthorization(Video $video, int $ttlMinutes): DownloadAuthorization
    {
        $authorization = $this->createPlaybackAuthorization($video, $ttlMinutes);

        return new DownloadAuthorization(
            downloadUrl: sprintf('%s/%s/downloads/default.mp4', $this->deliveryHost(), $authorization->token),
            expiresAt: $authorization->expiresAt,
        );
    }

    public function deleteAsset(string $assetId): void
    {
        $response = $this->request()->delete($this->accountUrl("/stream/{$assetId}"));

        $this->guard($response->successful(), 'deleteAsset', $response->json());
    }

    private function request(): PendingRequest
    {
        $token = (string) config('services.cloudflare_stream.api_token');

        if ($token === '') {
            throw VideoProviderException::notConfigured($this->key());
        }

        return Http::withToken($token)
            ->asJson()
            ->timeout((int) config('services.cloudflare_stream.timeout', 15));
    }

    private function accountUrl(string $path): string
    {
        $accountId = (string) config('services.cloudflare_stream.account_id');

        if ($accountId === '') {
            throw VideoProviderException::notConfigured($this->key());
        }

        return "https://api.cloudflare.com/client/v4/accounts/{$accountId}{$path}";
    }

    private function deliveryHost(): string
    {
        return rtrim((string) config('services.cloudflare_stream.delivery_host'), '/');
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function guard(bool $successful, string $operation, ?array $body): void
    {
        if ($successful) {
            return;
        }

        // Provider errors can carry asset identifiers but never tokens; log the errors
        // array only.
        Log::warning('Cloudflare Stream request failed', [
            'operation' => $operation,
            'errors' => $body['errors'] ?? null,
        ]);

        throw VideoProviderException::requestFailed($this->key(), $operation);
    }
}
