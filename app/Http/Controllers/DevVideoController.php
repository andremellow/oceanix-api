<?php

namespace App\Http\Controllers;

use App\Services\Video\FakeVideoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function show(string $asset, FakeVideoProvider $provider): StreamedResponse
    {
        abort_unless(Storage::disk(FakeVideoProvider::DISK)->exists($provider->path($asset)), 404);

        return Storage::disk(FakeVideoProvider::DISK)->response($provider->path($asset));
    }
}
