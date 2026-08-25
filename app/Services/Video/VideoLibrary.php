<?php

namespace App\Services\Video;

use App\Contracts\VideoProvider;
use Illuminate\Support\Carbon;

class VideoLibrary
{
    public function __construct(private readonly VideoProvider $provider) {}

    /** @return list<array<string, mixed>> */
    public function items(string $search = ''): array
    {
        return collect($this->provider->listAssets(search: $search))
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
                ];
            })
            ->all();
    }
}
