<?php

namespace App\Actions\PlatformTasks;

use Andremellow\Tasks\Actions\MoveTask;
use Andremellow\Tasks\Enums\TaskStatus;
use Andremellow\Tasks\Models\Task;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ResolvePlatformTask
{
    public function __construct(private MoveTask $move) {}

    public function handle(Authenticatable $actor, Task $task): Task
    {
        Gate::forUser($actor)->authorize('changeStatus', $task);

        return DB::transaction(function () use ($actor, $task): Task {
            // Every API call resolves as the configured canonical actor. Locking that stable
            // row serializes concurrent resolves, including the empty-done-column case where
            // PostgreSQL has no destination row on which to take a lock.
            DB::table('users')->where('id', $actor->getAuthIdentifier())->lockForUpdate()->first();

            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->status === TaskStatus::Done) {
                return $task;
            }

            $done = Task::query()
                ->where('status', TaskStatus::Done->value)
                ->lockForUpdate()
                ->orderBy('board_position')
                ->orderBy('id')
                ->get(['id', 'board_position']);

            return $this->move->handle($actor, $task, TaskStatus::Done, ((int) $done->max('board_position')) + 1);
        });
    }
}
