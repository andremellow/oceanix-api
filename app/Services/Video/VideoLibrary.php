<?php

namespace App\Services\Video;

use App\Contracts\VideoProvider;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

class VideoLibrary
{
    public function __construct(
        private readonly VideoProvider $provider,
        private readonly TenantContext $tenant,
    ) {}

    /** @return list<array<string, mixed>> */
    public function items(string $search = '', bool $allOwners = false): array
    {
        $company = $this->tenant->get();
        $ownerKey = $allOwners ? '*' : ($company === null ? 'platform' : 'company:'.$company->id);

        return collect($this->provider->listAssets(search: $search, ownerKey: $ownerKey))
            ->map(function ($item): array {
                $preview = $item->status->value === 'ready'
                    ? rescue(
                        fn () => $this->provider->createAssetPreviewAuthorization($item->assetId, $item->hlsUrl, 15),
                        report: false,
                    )
                    : null;

                return [
                    'asset_id' => $item->assetId,
                    'title' => $item->title,
                    'status' => $item->status->value,
                    'status_label' => $item->status->label(),
                    'pill' => $item->status->pillModifier(),
                    'duration' => $item->durationSeconds === null
                        ? '—'
                        : sprintf('%d:%02d', intdiv($item->durationSeconds, 60), $item->durationSeconds % 60),
                    'created_at' => $item->createdAt === null
                        ? '—'
                        : rescue(fn (): string => Carbon::parse($item->createdAt)->translatedFormat('M j, Y'), '—', report: false),
                    'thumbnail_url' => $preview?->posterUrl,
                    'preview_url' => $preview?->playbackUrl,
                    'aspect_ratio' => $this->aspectRatio($item->width, $item->height),
                ];
            })
            ->all();
    }

    /** @return array{preview_url: string, poster_url: string|null, aspect_ratio: string}|null */
    public function preview(Video $video): ?array
    {
        if ($video->status !== VideoStatus::Ready) {
            return null;
        }

        $authorization = $this->provider->createAssetPreviewAuthorization(
            $video->provider_asset_id,
            is_string($video->metadata['hls'] ?? null) ? $video->metadata['hls'] : null,
            15,
        );

        return [
            'preview_url' => $authorization->playbackUrl,
            'poster_url' => $authorization->posterUrl,
            'aspect_ratio' => $this->aspectRatio($video->metadata['width'] ?? null, $video->metadata['height'] ?? null),
        ];
    }

    private function aspectRatio(mixed $width, mixed $height): string
    {
        return is_numeric($width) && is_numeric($height) && (int) $width > 0 && (int) $height > 0
            ? ((int) $width).'/'.((int) $height)
            : '16/9';
    }
}
