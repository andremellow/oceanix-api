<?php

use App\Actions\Videos\LinkExistingVideo;
use App\Exceptions\VideoProviderException;
use App\Models\Lesson;
use App\Services\Video\CloudflareStreamProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

it('tags uploads and only lists assets owned by the active company', function (): void {
    $company = currentCompany();
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/*/stream/direct_upload' => Http::response([
            'result' => ['uid' => 'new-asset', 'uploadURL' => 'https://upload.example/new-asset'],
        ]),
        'api.cloudflare.com/client/v4/accounts/*/stream?*' => Http::response(['result' => [
            ['uid' => 'owned', 'meta' => ['name' => 'Owned', 'oceanix_owner' => 'company:'.$company->id], 'status' => ['state' => 'ready']],
            ['uid' => 'foreign', 'meta' => ['name' => 'Foreign', 'oceanix_owner' => 'company:999'], 'status' => ['state' => 'ready']],
            ['uid' => 'untagged', 'meta' => ['name' => 'Untagged'], 'status' => ['state' => 'ready']],
        ]]),
    ]);
    $provider = app(CloudflareStreamProvider::class);

    $provider->createUpload('Safety', 3600, 'company:'.$company->id);
    $assets = $provider->listAssets(ownerKey: 'company:'.$company->id);

    expect(collect($assets)->pluck('assetId')->all())->toBe(['owned']);
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && data_get($request->data(), 'meta.oceanix_owner') === 'company:'.$company->id);
});

it('rejects unsigned and cross-company assets before linking', function (bool $signed, string $owner): void {
    $company = currentCompany();
    $owner = $owner === 'current' ? 'company:'.$company->id : $owner;
    $lesson = Lesson::factory()->create(['company_id' => $company->id]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['result' => [
        'uid' => 'unsafe-asset', 'status' => ['state' => 'ready'], 'requireSignedURLs' => $signed,
        'meta' => ['oceanix_owner' => $owner],
    ]])]);

    expect(fn () => app(LinkExistingVideo::class)->handle($lesson, 'unsafe-asset'))
        ->toThrow(ValidationException::class);
    expect($lesson->fresh()->video)->toBeNull();
})->with([
    'unsigned company asset' => [false, 'current'],
    'signed foreign asset' => [true, 'company:999'],
]);

it('normalizes Cloudflare connection failures for the library UI', function (): void {
    Http::fake(fn () => Http::failedConnection());

    expect(fn () => app(CloudflareStreamProvider::class)->listAssets(ownerKey: 'company:'.currentCompany()->id))
        ->toThrow(VideoProviderException::class);
});
