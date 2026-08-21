<?php

namespace App\Services\Settings;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Operational settings, overlaid on configuration.
 *
 * Configuration remains the default and the deployment contract; a stored value only
 * overrides it. Nothing secret is ever stored here — credentials belong in the environment,
 * where they are not editable through a web form.
 */
class ApplicationSettings
{
    /** Keys an administrator is allowed to change, with their validation rules. */
    public const EDITABLE = [
        'oceanix.due_soon_days' => ['integer', 'min:1', 'max:180'],
        'oceanix.critical_overdue_days' => ['integer', 'min:1', 'max:365'],
        'oceanix.overdue_reminder_days' => ['integer', 'min:1', 'max:90'],
        'oceanix.playback_token_minutes' => ['integer', 'min:5', 'max:120'],
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? config($key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $companyId = app(TenantContext::class)->id();

        return Cache::rememberForever($this->cacheKey(), fn (): array => DB::table('settings')
            ->where('company_id', $companyId)
            ->pluck('value', 'key')
            ->map(fn (?string $value) => json_decode((string) $value, true))
            ->all());
    }

    public function set(string $key, mixed $value): void
    {
        abort_unless(array_key_exists($key, self::EDITABLE), 422);

        $companyId = app(TenantContext::class)->id();

        DB::table('settings')->updateOrInsert(
            ['company_id' => $companyId, 'key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );

        $this->flush();
    }

    /** Applied at boot so the whole application reads the effective value. */
    public function apply(): void
    {
        foreach ($this->all() as $key => $value) {
            if (array_key_exists($key, self::EDITABLE)) {
                config([$key => $value]);
            }
        }
    }

    public function flush(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'oceanix.settings.'.app(TenantContext::class)->id();
    }
}
