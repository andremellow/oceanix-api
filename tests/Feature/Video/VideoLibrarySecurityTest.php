<?php

use App\Actions\Videos\LinkExistingVideo;
use App\Actions\Videos\RequestVideoUpload;
use App\Actions\Videos\SyncVideoAsset;
use App\Exceptions\VideoProviderException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Lesson;
use App\Models\ModuleVersion;
use App\Models\Video;
use App\Services\Modules\ModuleVersionValidator;
use App\Services\Video\CloudflareStreamProvider;
use App\Tenancy\TenantContext;
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

it('lists videos from every owner for the platform administration library', function (): void {
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/*/stream?*' => Http::response(['result' => [
            ['uid' => 'platform-video', 'meta' => ['name' => 'Platform', 'oceanix_owner' => 'platform'], 'status' => ['state' => 'ready']],
            ['uid' => 'company-video', 'meta' => ['name' => 'Company', 'oceanix_owner' => 'company:99'], 'status' => ['state' => 'ready']],
            ['uid' => 'legacy-video', 'meta' => ['name' => 'Legacy'], 'status' => ['state' => 'ready']],
        ]]),
    ]);

    $assets = app(CloudflareStreamProvider::class)->listAssets(ownerKey: '*');

    expect(collect($assets)->pluck('assetId')->all())
        ->toBe(['platform-video', 'company-video', 'legacy-video']);
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

it('allows platform administration to link a ready signed company video', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $lesson = Lesson::factory()->create(['company_id' => null, 'is_shared' => true]);
    app(TenantContext::class)->clear();
    Http::fake(['api.cloudflare.com/*' => Http::response(['result' => [
        'uid' => 'company-asset',
        'status' => ['state' => 'ready'],
        'requireSignedURLs' => true,
        'meta' => ['oceanix_owner' => 'company:99'],
    ]])]);

    app(LinkExistingVideo::class)->handle($lesson, 'company-asset', allowAnyOwner: true, platformActor: $actor);

    expect($lesson->fresh()->video?->provider_asset_id)->toBe('company-asset');
    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'lesson.video_linked')->sole();
    expect($audit->company_id)->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->platform_account_id)->toBe($actor->id);
});

it('records platform video upload requests without requiring a tenant', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $lesson = Lesson::factory()->create(['company_id' => null, 'is_shared' => true]);
    app(TenantContext::class)->clear();
    Http::fake(['api.cloudflare.com/*' => Http::response(['result' => [
        'uid' => 'platform-upload',
        'uploadURL' => 'https://upload.cloudflarestream.com/platform-upload',
    ]])]);

    app(RequestVideoUpload::class)->handle($lesson, platformActor: $actor);

    expect($lesson->fresh()->video?->provider_asset_id)->toBe('platform-upload');
    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'lesson.video_upload_requested')->sole();
    expect($audit->company_id)->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->platform_account_id)->toBe($actor->id);
});

it('never lets an older ready candidate replace a newer requested video', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $lesson = Lesson::factory()->create(['company_id' => null, 'is_shared' => true, 'status' => 'draft']);
    app(TenantContext::class)->clear();
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'direct_upload')) {
            static $sequence = 0;
            $asset = ++$sequence === 1 ? 'candidate-a' : 'candidate-b';

            return Http::response(['result' => ['uid' => $asset, 'uploadURL' => "https://upload.example/{$asset}"]]);
        }

        $asset = str_contains($request->url(), 'candidate-a') ? 'candidate-a' : 'candidate-b';

        return Http::response(['result' => ['uid' => $asset, 'status' => ['state' => 'ready'], 'requireSignedURLs' => true, 'playback' => ['hls' => "https://video.example/{$asset}.m3u8"], 'meta' => ['oceanix_owner' => 'platform']]]);
    });

    $first = app(RequestVideoUpload::class)->handle($lesson, platformActor: $actor);
    $second = app(RequestVideoUpload::class)->handle($lesson, platformActor: $actor);
    $a = Video::query()->findOrFail($first->videoId);
    $b = Video::query()->findOrFail($second->videoId);

    app(SyncVideoAsset::class)->handle($b);
    app(SyncVideoAsset::class)->handle($a);

    expect($lesson->fresh()->video?->id)->toBe($b->id)
        ->and($a->fresh()->is_current)->toBeFalse()
        ->and($b->fresh()->is_current)->toBeTrue();
});

it('blocks publication while a replacement candidate is processing', function (): void {
    $module = ModuleVersion::factory()->create(['company_id' => null, 'is_shared' => true, 'status' => 'draft']);
    Video::factory()->create(['lesson_id' => $module->id, 'company_id' => null, 'status' => 'ready', 'is_current' => true, 'replacement_generation' => 1]);
    Video::factory()->create(['lesson_id' => $module->id, 'company_id' => null, 'status' => 'processing', 'is_current' => false, 'replacement_generation' => 2]);

    expect(app(ModuleVersionValidator::class)->problems($module))
        ->toContain(__('Lesson :position (:title) has a video replacement that is still processing.', ['position' => $module->position, 'title' => $module->title]));
});

it('synchronizes a platform video even when there are no companies', function (): void {
    Company::query()->delete();
    app(TenantContext::class)->clear();
    $lesson = Lesson::query()->create(['company_id' => null, 'is_shared' => true, 'status' => 'draft', 'title' => 'Platform module', 'type' => 'video']);
    $video = Video::factory()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'status' => 'processing', 'is_current' => true, 'replacement_generation' => 1]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['result' => [
        'uid' => $video->provider_asset_id, 'status' => ['state' => 'ready'], 'requireSignedURLs' => true,
        'playback' => ['hls' => 'https://video.example/platform.m3u8'], 'meta' => ['oceanix_owner' => 'platform'],
    ]])]);

    $this->artisan('oceanix:sync-videos')->assertSuccessful();

    expect($video->fresh()->status->value)->toBe('ready');
});

it('normalizes Cloudflare connection failures for the library UI', function (): void {
    Http::fake(fn () => Http::failedConnection());

    expect(fn () => app(CloudflareStreamProvider::class)->listAssets(ownerKey: 'company:'.currentCompany()->id))
        ->toThrow(VideoProviderException::class);
});
