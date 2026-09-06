<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LocalVideoStream
{
    public function response(string $asset, FakeVideoProvider $provider): BinaryFileResponse
    {
        abort_unless(preg_match('/^[a-zA-Z0-9-]+$/D', $asset), 404);
        $disk = Storage::disk(FakeVideoProvider::DISK);
        abort_unless($disk->exists($provider->path($asset)), 404);

        return response()->file($disk->path($provider->path($asset)), ['Accept-Ranges' => 'bytes']);
    }
}
