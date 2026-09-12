<?php

namespace App\Services\CourseEditor;

use App\Models\ContentImage;

final class EditorContentImageLibrary
{
    /** @return list<array{id: int, name: string, url: string}> */
    public function company(int $companyId): array
    {
        return $this->map(ContentImage::query()->where('company_id', $companyId)->where('is_shared', false)->latest()->limit(60)->get());
    }

    /** @return list<array{id: int, name: string, url: string}> */
    public function platform(): array
    {
        return $this->map(ContentImage::query()->whereNull('company_id')->where('is_shared', true)->latest()->limit(60)->get());
    }

    /** @return array{id: int, name: string, url: string} */
    public function companyImage(int $companyId, int $imageId): array
    {
        return $this->item(ContentImage::query()->where('company_id', $companyId)->where('is_shared', false)->findOrFail($imageId));
    }

    /** @return array{id: int, name: string, url: string} */
    public function platformImage(int $imageId): array
    {
        return $this->item(ContentImage::query()->whereNull('company_id')->where('is_shared', true)->findOrFail($imageId));
    }

    private function map($images): array
    {
        return $images->map(fn (ContentImage $image): array => $this->item($image))->values()->all();
    }

    private function item(ContentImage $image): array
    {
        return ['id' => $image->id, 'name' => $image->name, 'url' => $image->url()];
    }
}
