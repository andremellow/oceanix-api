<?php

use App\Contracts\VideoProvider;
use App\Services\Video\CloudflareStreamProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.cloudflare_stream.account_id', 'account-id');
    config()->set('services.cloudflare_stream.api_token', 'token');
});

function fakeCloudflare(array $overrides = []): void
{
    Http::fake([
        'api.cloudflare.com/client/v4/user/tokens/verify' => $overrides['verify']
            ?? Http::response(['success' => true, 'result' => ['status' => 'active']]),
        'api.cloudflare.com/client/v4/accounts/*/stream/direct_upload' => $overrides['upload']
            ?? Http::response(['success' => true, 'result' => ['uid' => 'asset-1', 'uploadURL' => 'https://upload.test']]),
        'api.cloudflare.com/client/v4/accounts/*/stream/asset-1' => $overrides['delete']
            ?? Http::response(['success' => true]),
        'api.cloudflare.com/client/v4/accounts/*/stream*' => $overrides['list']
            ?? Http::response(['success' => true, 'result' => []]),
    ]);
}

it('passes every check when the account, token and scope are right', function (): void {
    fakeCloudflare();

    $this->artisan('oceanix:video-check')
        ->assertSuccessful();
});

it('removes the temporary upload slot it creates', function (): void {
    fakeCloudflare();

    $this->artisan('oceanix:video-check')->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/stream/asset-1'));
});

it('reports a revoked token instead of claiming the provider is ready', function (): void {
    fakeCloudflare(['verify' => Http::response([
        'success' => false,
        'errors' => [['code' => 1000, 'message' => 'Invalid API Token']],
    ], 401)]);

    $this->artisan('oceanix:video-check')
        ->expectsOutputToContain('Invalid API Token')
        ->assertFailed();
});

it('fails when the token is valid but the account cannot be read', function (): void {
    fakeCloudflare(['list' => Http::response([
        'success' => false,
        'errors' => [['code' => 7003, 'message' => 'Could not route to /accounts/account-id/stream']],
    ], 404)]);

    $this->artisan('oceanix:video-check')->assertFailed();
});

it('fails when the token cannot write, even though it can read', function (): void {
    fakeCloudflare(['upload' => Http::response([
        'success' => false,
        'errors' => [['code' => 10000, 'message' => 'Authentication error']],
    ], 403)]);

    $this->artisan('oceanix:video-check')->assertFailed();
});

it('skips the write check when asked to', function (): void {
    fakeCloudflare();

    $this->artisan('oceanix:video-check', ['--no-write' => true])->assertSuccessful();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'direct_upload'));
});

it('reports missing credentials without calling out to the network', function (): void {
    config()->set('services.cloudflare_stream.api_token', null);
    Http::fake();

    expect((new CloudflareStreamProvider)->verifyConfiguration())
        ->toHaveCount(1)
        ->and((new CloudflareStreamProvider)->verifyConfiguration()[0]['ok'])->toBeFalse();

    Http::assertNothingSent();
});

it('never contacts Cloudflare when the local provider is in use', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('services.cloudflare_stream.account_id', null);
    config()->set('services.cloudflare_stream.api_token', null);
    app()->forgetInstance(VideoProvider::class);
    Http::fake();

    $this->artisan('oceanix:video-check')->assertSuccessful();

    Http::assertNothingSent();
});
