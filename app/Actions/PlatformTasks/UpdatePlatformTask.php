<?php

namespace App\Actions\PlatformTasks;

use Andremellow\Tasks\Actions\AssignTask;
use Andremellow\Tasks\Actions\UpdateTask;
use Andremellow\Tasks\Enums\TaskPriority;
use Andremellow\Tasks\Models\Task;
use Andremellow\Tasks\Services\EligibleTaskAssignees;
use Andremellow\Tasks\Services\TaskUsers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdatePlatformTask
{
    public function __construct(
        private AssignTask $assign,
        private UpdateTask $update,
        private EligibleTaskAssignees $eligibleAssignees,
        private TaskUsers $users,
    ) {}

    public function handle(Authenticatable $actor, Task $task, array $input): Task
    {
        Gate::forUser($actor)->authorize('update', $task);

        $data = Validator::make($input, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('tasks.description_max', 100000)],
            'task_type_id' => ['sometimes', Rule::exists('task_types', 'id')->whereNull('deleted_at')],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => [Rule::exists('task_tags', 'id')->whereNull('deleted_at')],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();

        $assignee = null;
        if (array_key_exists('assignee_id', $data) && $data['assignee_id'] !== null) {
            $assignee = $this->users->find($data['assignee_id']);

            if (! $this->eligibleAssignees->eligible($assignee)) {
                throw ValidationException::withMessages(['assignee_id' => __('The selected assignee is not eligible.')]);
            }
        }

        return DB::transaction(function () use ($actor, $task, $data, $assignee): Task {
            if (array_key_exists('assignee_id', $data)) {
                $task = $this->assign->handle($actor, $task, $assignee);
            }

            $attributes = Arr::except($data, ['assignee_id']);
            if ($attributes !== []) {
                $task = $this->update->handle($actor, $task, $attributes);
            }

            return $task->load(['type', 'tags', 'assignee']);
        });
    }
}
