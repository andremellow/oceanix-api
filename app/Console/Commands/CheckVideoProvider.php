<?php

namespace App\Console\Commands;

use App\Contracts\VideoProvider;
use Illuminate\Console\Command;

/**
 * Operator check for the configured video provider.
 *
 * Verifying the API token alone is not enough: a valid token can still point at the wrong
 * account or lack Stream write scope, and both failures would only surface when someone
 * tries to upload a lesson video.
 */
class CheckVideoProvider extends Command
{
    protected $signature = 'oceanix:video-check {--no-write : Skip the write check, which creates and removes a temporary upload slot}';

    protected $description = 'Verify that the configured video provider is usable';

    public function handle(VideoProvider $provider): int
    {
        $this->newLine();
        $this->line('  Provider in use: <options=bold>'.$provider->key().'</>');
        $this->newLine();

        $checks = $provider->verifyConfiguration(write: ! $this->option('no-write'));

        foreach ($checks as $check) {
            $this->line(sprintf(
                '  %s %s',
                $check['ok'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
                $check['label'],
            ));

            if (($check['detail'] ?? null) !== null) {
                $this->line('       <fg=gray>'.$check['detail'].'</>');
            }
        }

        $failed = collect($checks)->contains(fn (array $check): bool => ! $check['ok']);

        $this->newLine();

        if ($failed) {
            $this->line('  <fg=red>The provider is not ready.</> Uploading a lesson video would fail.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('  <fg=green>The provider is ready.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
