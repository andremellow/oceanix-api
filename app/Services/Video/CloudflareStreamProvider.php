<?php

namespace App\Services\Video;

use App\Contracts\VideoProvider;
use App\Data\Video\DownloadAuthorization;
use App\Data\Video\PlaybackAuthorization;
use App\Data\Video\VideoAssetStatus;
use App\Data\Video\VideoLibraryItem;
use App\Data\Video\VideoUpload;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Models\Video;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    /**
     * Both credentials are required: the account id addresses the account, the API token
     * authenticates against it. Having only one is the same as having neither.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.cloudflare_stream.account_id'))
            && filled(config('services.cloudflare_stream.api_token'));
    }

    /**
     * Three separate questions, because they fail for different reasons: is the token
     * valid, does it reach this account's Stream, and may it write. A token can pass the
     * first and fail the others.
     *
     * @return list<array{label: string, ok: bool, detail: string|null}>
     */
    public function verifyConfiguration(bool $write = true): array
    {
        if (! self::isConfigured()) {
            return [[
                'label' => 'Credentials present',
                'ok' => false,
                'detail' => 'CLOUDFLARE_STREAM_ACCOUNT_ID and CLOUDFLARE_STREAM_API_TOKEN must both be set.',
            ]];
        }

        $checks = [];

        $verify = $this->safely(fn () => $this->request()->get('https://api.cloudflare.com/client/v4/user/tokens/verify'));
        $status = $verify?->json('result.status');
        $checks[] = [
            'label' => 'API token is valid',
            'ok' => $verify?->successful() === true && $status === 'active',
            'detail' => $verify === null
                ? 'Could not reach the Cloudflare API.'
                : ($verify->successful() ? 'Token status: '.$status : $this->firstError($verify)),
        ];

        $stream = $this->safely(fn () => $this->request()->get($this->accountUrl('/stream'), ['per_page' => 1]));
        $checks[] = [
            'label' => 'Account reachable with Stream read access',
            'ok' => $stream?->successful() === true,
            'detail' => $stream === null
                ? 'Could not reach the Cloudflare API.'
                : ($stream->successful()
                    ? 'Videos in this account: '.(is_array($stream->json('result')) ? count($stream->json('result')) : 0).'+'
                    : $this->firstError($stream)),
        ];

        if (! $write) {
            return $checks;
        }

        // The only honest proof of write scope is a write. The slot is removed right after,
        // so a check never leaves an asset behind.
        $upload = $this->safely(fn () => $this->request()->post($this->accountUrl('/stream/direct_upload'), [
            'maxDurationSeconds' => 60,
            'requireSignedURLs' => true,
            'meta' => ['name' => 'Oceanix configuration check'],
        ]));

        $assetId = $upload?->json('result.uid');
        $cleaned = null;

        if (is_string($assetId) && $assetId !== '') {
            $cleaned = $this->safely(fn () => $this->request()->delete($this->accountUrl("/stream/{$assetId}")))?->successful() === true;
        }

        $checks[] = [
            'label' => 'Stream write access (upload slot created and removed)',
            'ok' => $upload?->successful() === true,
            'detail' => $upload === null
                ? 'Could not reach the Cloudflare API.'
                : ($upload->successful()
                    ? ($cleaned === true ? 'Test slot removed.' : 'Test slot created but could not be removed: '.$assetId)
                    : $this->firstError($upload)),
        ];

        return $checks;
    }

    public function createUpload(string $title, int $maxDurationSeconds, string $ownerKey): VideoUpload
    {
        $response = $this->request()->post($this->accountUrl('/stream/direct_upload'), [
            'maxDurationSeconds' => $maxDurationSeconds,
            'requireSignedURLs' => true,
            'meta' => ['name' => $title, 'oceanix_owner' => $ownerKey],
        ]);

        $this->guard($response->successful(), 'createUpload', $response->json());

        return new VideoUpload(
            provider: $this->key(),
            assetId: (string) $response->json('result.uid'),
            uploadUrl: (string) $response->json('result.uploadURL'),
        );
    }

    /** @return list<VideoLibraryItem> */
    public function listAssets(int $limit = 12, string $search = '', string $ownerKey = ''): array
    {
        $query = [
            'limit' => 1000,
        ];

        if (trim($search) !== '') {
            $query['search'] = trim($search);
        }

        try {
            $response = $this->request()->get($this->accountUrl('/stream'), $query);
        } catch (ConnectionException) {
            throw VideoProviderException::requestFailed($this->key(), 'listAssets');
        }

        $this->guard($response->successful(), 'listAssets', $response->json());

        return collect($response->json('result', []))
            ->filter(fn (mixed $asset): bool => is_array($asset)
                && filled($asset['uid'] ?? null)
                && $ownerKey !== ''
                && ($ownerKey === '*' || data_get($asset, 'meta.oceanix_owner') === $ownerKey))
            ->map(function (array $asset): VideoLibraryItem {
                $state = (string) data_get($asset, 'status.state', '');
                $duration = $asset['duration'] ?? null;

                return new VideoLibraryItem(
                    assetId: (string) $asset['uid'],
                    title: (string) (data_get($asset, 'meta.name') ?: __('Untitled video')),
                    status: match ($state) {
                        'ready' => VideoStatus::Ready,
                        'inprogress', 'queued', 'downloading' => VideoStatus::Processing,
                        'error' => VideoStatus::Failed,
                        default => VideoStatus::Uploading,
                    },
                    durationSeconds: is_numeric($duration) ? (int) round((float) $duration) : null,
                    createdAt: is_string($asset['created'] ?? null) ? $asset['created'] : null,
                    hlsUrl: is_string(data_get($asset, 'playback.hls')) ? data_get($asset, 'playback.hls') : null,
                    width: is_numeric(data_get($asset, 'input.width')) ? (int) data_get($asset, 'input.width') : null,
                    height: is_numeric(data_get($asset, 'input.height')) ? (int) data_get($asset, 'input.height') : null,
                );
            })
            ->take(min(max($limit, 1), 1000))
            ->values()
            ->all();
    }

    public function createAssetPreviewAuthorization(string $assetId, ?string $hlsUrl, int $ttlMinutes): PlaybackAuthorization
    {
        $expiresAt = now()->addMinutes($ttlMinutes);
        $response = $this->request()->post(
            $this->accountUrl("/stream/{$assetId}/token"),
            ['exp' => $expiresAt->getTimestamp()],
        );

        $this->guard($response->successful(), 'createAssetPreviewAuthorization', $response->json());

        $token = (string) $response->json('result.token');
        $host = $this->deliveryHostFromHls($hlsUrl);

        return new PlaybackAuthorization(
            token: $token,
            playbackUrl: sprintf('%s/%s/manifest/video.m3u8', $host, $token),
            expiresAt: $expiresAt,
            posterUrl: sprintf('%s/%s/thumbnails/thumbnail.jpg', $host, $token),
        );
    }

    public function getAssetStatus(string $assetId): VideoAssetStatus
    {
        try {
            $response = $this->request()->get($this->accountUrl("/stream/{$assetId}"));
        } catch (ConnectionException) {
            throw VideoProviderException::requestFailed($this->key(), 'getAssetStatus');
        }

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
                // Carries the account's delivery host, which differs per account.
                'hls' => $response->json('result.playback.hls'),
                'oceanix_owner' => $response->json('result.meta.oceanix_owner'),
                'require_signed_urls' => $response->json('result.requireSignedURLs') === true,
                'width' => is_numeric($response->json('result.input.width')) ? (int) $response->json('result.input.width') : null,
                'height' => is_numeric($response->json('result.input.height')) ? (int) $response->json('result.input.height') : null,
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
        $host = $this->deliveryHostFor($video);

        return new PlaybackAuthorization(
            token: $token,
            playbackUrl: sprintf('%s/%s/manifest/video.m3u8', $host, $token),
            expiresAt: $expiresAt,
            posterUrl: sprintf('%s/%s/thumbnails/thumbnail.jpg', $host, $token),
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

    /** Network and configuration failures become a null response, not an exception. */
    private function safely(callable $call): ?Response
    {
        try {
            return $call();
        } catch (ConnectionException|VideoProviderException) {
            return null;
        }
    }

    private function firstError(Response $response): string
    {
        $errors = $response->json('errors');
        $first = is_array($errors) ? ($errors[0] ?? null) : null;

        return is_array($first)
            ? trim(sprintf('%s (code %s)', $first['message'] ?? 'Request failed', $first['code'] ?? '?'))
            : 'HTTP '.$response->status();
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

    /**
     * Prefer the host Cloudflare itself reported for this asset; fall back to configuration
     * only when the asset was never reconciled.
     */
    private function deliveryHostFor(Video $video): string
    {
        $hls = $video->metadata['hls'] ?? null;

        return $this->deliveryHostFromHls(is_string($hls) ? $hls : null);
    }

    private function deliveryHostFromHls(?string $hls): string
    {

        if (is_string($hls) && preg_match('#^(https://[^/]+)/#', $hls, $matches) === 1) {
            return $matches[1];
        }

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
