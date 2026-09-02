<?php

namespace App\Http\Controllers\Api\Platform;

use Andremellow\Tasks\Actions\AddTaskComment;
use Andremellow\Tasks\Actions\CreateTask;
use Andremellow\Tasks\Actions\MoveTask;
use Andremellow\Tasks\Enums\TaskPriority;
use Andremellow\Tasks\Enums\TaskStatus;
use Andremellow\Tasks\Models\Task;
use Andremellow\Tasks\Models\TaskTag;
use Andremellow\Tasks\Models\TaskType;
use Andremellow\Tasks\Services\EligibleTaskAssignees;
use App\Actions\PlatformTasks\ResolvePlatformTask;
use App\Actions\PlatformTasks\UpdatePlatformTask;
use App\Http\Resources\PlatformTaskResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Task::class);
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'assignee_id' => ['sometimes', 'integer'],
            'task_type_id' => ['sometimes', 'integer'],
            'tag_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $tasks = Task::query()
            ->with(['type', 'tags', 'assignee'])
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['priority']), fn (Builder $query) => $query->where('priority', $filters['priority']))
            ->when(array_key_exists('assignee_id', $filters), fn (Builder $query) => $query->where('assignee_id', $filters['assignee_id']))
            ->when(isset($filters['task_type_id']), fn (Builder $query) => $query->where('task_type_id', $filters['task_type_id']))
            ->when(isset($filters['tag_id']), fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($filters['tag_id'])))
            ->when(isset($filters['search']), fn (Builder $query) => $query->where(function (Builder $search) use ($filters): void {
                $search->whereLike('title', '%'.trim($filters['search']).'%', caseSensitive: false)
                    ->orWhereLike('description', '%'.trim($filters['search']).'%', caseSensitive: false);
            }))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return PlatformTaskResource::collection($tasks);
    }

    public function show(Task $task): PlatformTaskResource
    {
        Gate::authorize('view', $task);

        return new PlatformTaskResource($task->load(['type', 'tags', 'assignee']));
    }

    public function store(Request $request, CreateTask $action): JsonResponse
    {
        $task = $action->handle($request->user(), $request->all());

        return (new PlatformTaskResource($task))->response()->setStatusCode(201);
    }

    public function update(Request $request, Task $task, UpdatePlatformTask $action): PlatformTaskResource
    {
        $task = $action->handle($request->user(), $task, $request->all());

        return new PlatformTaskResource($task->load(['type', 'tags', 'assignee']));
    }

    public function move(Request $request, Task $task, MoveTask $action): PlatformTaskResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'position' => ['required', 'integer', 'min:1'],
        ]);

        return new PlatformTaskResource($action->handle(
            $request->user(),
            $task,
            TaskStatus::from($data['status']),
            $data['position'],
        )->load(['type', 'tags', 'assignee']));
    }

    public function comments(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task);
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $comments = $task->comments()->with('author')->orderBy('created_at')->orderBy('id')->paginate($data['per_page'] ?? 25);

        return response()->json($comments->through(fn ($comment): array => $this->commentData($comment)));
    }

    public function storeComment(Request $request, Task $task, AddTaskComment $action): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.config('tasks.comment_max', 5000)]]);
        $comment = $action->handle($request->user(), $task, $data['body']);

        return response()->json(['data' => $this->commentData($comment)], 201);
    }

    public function resolve(Request $request, Task $task, ResolvePlatformTask $action): PlatformTaskResource
    {
        $task = $action->handle($request->user(), $task);

        return new PlatformTaskResource($task->load(['type', 'tags', 'assignee']));
    }

    public function meta(EligibleTaskAssignees $assignees): JsonResponse
    {
        Gate::authorize('viewAny', Task::class);

        return response()->json(['data' => [
            'types' => TaskType::active()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug', 'color']),
            'tags' => TaskTag::active()->orderBy('name')->orderBy('id')->get(['id', 'name', 'slug', 'color']),
            'assignees' => $assignees->query()->get(['id', 'name', 'email']),
            'statuses' => collect(TaskStatus::cases())->map(fn (TaskStatus $status): array => ['value' => $status->value, 'label' => $status->label()]),
            'priorities' => collect(TaskPriority::cases())->map(fn (TaskPriority $priority): array => ['value' => $priority->value, 'label' => $priority->label()]),
        ]]);
    }

    private function commentData($comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'author' => $comment->author === null ? null : [
                'id' => $comment->author->id,
                'name' => $comment->author->name,
                'email' => $comment->author->email,
            ],
            'created_at' => $comment->created_at?->toISOString(),
            'updated_at' => $comment->updated_at?->toISOString(),
        ];
    }
}
