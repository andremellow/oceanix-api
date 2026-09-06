<?php

namespace App\Http\Controllers;

use App\Services\Video\FakeVideoProvider;
use App\Services\Video\LocalVideoStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Endpoints for the local development video provider. Registered only in the local
 * environment, and every URL is signed and short-lived.
 */
class DevVideoController extends Controller
{
    public function store(Request $request, string $asset, FakeVideoProvider $provider): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska'],
        ]);

        $request->file('file')->storeAs('dev-videos', $asset, FakeVideoProvider::DISK);

        return response()->json(['stored' => true]);
    }

    public function show(string $asset, FakeVideoProvider $provider): BinaryFileResponse
    {
        return app(LocalVideoStream::class)->response($asset, $provider);
    }
}
