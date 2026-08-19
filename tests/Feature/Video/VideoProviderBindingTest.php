<?php

use App\Contracts\VideoProvider;
use App\Services\Video\CloudflareStreamProvider;
use App\Services\Video\FakeVideoProvider;

function bindProviderFor(string $environment, ?string $accountId, ?string $token): VideoProvider
{
    app()->detectEnvironment(fn (): string => $environment);
    config()->set('services.cloudflare_stream.account_id', $accountId);
    config()->set('services.cloudflare_stream.api_token', $token);

    app()->forgetInstance(VideoProvider::class);

    return app(VideoProvider::class);
}

it('needs both credentials before it treats Cloudflare as configured', function (): void {
    config()->set('services.cloudflare_stream.account_id', 'account');
    config()->set('services.cloudflare_stream.api_token', null);
    expect(CloudflareStreamProvider::isConfigured())->toBeFalse();

    config()->set('services.cloudflare_stream.account_id', null);
    config()->set('services.cloudflare_stream.api_token', 'token');
    expect(CloudflareStreamProvider::isConfigured())->toBeFalse();

    config()->set('services.cloudflare_stream.account_id', 'account');
    config()->set('services.cloudflare_stream.api_token', 'token');
    expect(CloudflareStreamProvider::isConfigured())->toBeTrue();
});

it('falls back locally only while Cloudflare is half configured or absent', function (?string $accountId, ?string $token): void {
    expect(bindProviderFor('local', $accountId, $token))->toBeInstanceOf(FakeVideoProvider::class);
})->with([
    'nothing set' => [null, null],
    'account id only' => ['account', null],
    'token only' => [null, 'token'],
]);

it('uses the real provider locally once both credentials exist', function (): void {
    expect(bindProviderFor('local', 'account', 'token'))->toBeInstanceOf(CloudflareStreamProvider::class);
});

it('never falls back outside local, however Cloudflare is configured', function (string $environment): void {
    expect(bindProviderFor($environment, null, null))->toBeInstanceOf(CloudflareStreamProvider::class);
})->with(['production', 'staging', 'testing']);
