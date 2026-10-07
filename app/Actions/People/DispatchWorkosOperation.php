<?php

namespace App\Actions\People;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class DispatchWorkosOperation
{
    public function handle(object $job): void
    {
        $lock = new UniqueLock(Cache::store());
        if (! $lock->acquire($job)) {
            throw new RuntimeException('queue_unavailable');
        }
        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (\Throwable $error) {
            $lock->release($job);
            throw $error;
        }
    }
}
