<?php

use Andremellow\Tasks\Models\Task;
use Andremellow\Tasks\Models\TaskChange;
use Andremellow\Tasks\Models\TaskComment;
use Andremellow\Tasks\Models\TaskStatusChange;
use Andremellow\Tasks\Models\TaskTag;
use Andremellow\Tasks\Models\TaskType;
use App\Models\Account;
use App\Models\User;

function configurePlatformTaskApi(array $account = [], array $user = []): array
{
    $key = 'test-key-'.str()->random(40);
    $platformAccount = Account::factory()->platformAdmin()->create($account);
    $actor = User::factory()->create([
        'account_id' => $platformAccount->id,
        'email' => $platformAccount->email,
        ...$user,
    ]);

    config([
        'tasks.platform_api.key_hash' => hash('sha256', $key),
        'tasks.platform_api.actor_email' => $actor->email,
    ]);

    return [$key, $platformAccount, $actor];
}

function platformTaskApiHeaders(string $key): array
{
    return ['X-Tasks-Key' => $key, 'Accept' => 'application/json'];
}

function createPlatformApiTask(User $actor, array $overrides = []): Task
{
    $type = TaskType::query()->firstOrCreate(['slug' => 'bug'], ['name' => 'Bug']);

    return Task::query()->create([
        'task_type_id' => $type->id,
        'created_by' => $actor->id,
        'title' => 'Investigate automation failure',
        'description' => 'Reproduce and repair the issue.',
        'priority' => 'high',
        'status' => 'backlog',
        'board_position' => 1,
        ...$overrides,
    ]);
}

it('requires a configured matching API key', function (): void {
    [$key] = configurePlatformTaskApi();

    $this->getJson('/api/platform/tasks/v1/tasks')->assertUnauthorized();
    $this->withHeaders(platformTaskApiHeaders('wrong-key'))->getJson('/api/platform/tasks/v1/tasks')->assertUnauthorized();

    config(['tasks.platform_api.key_hash' => null]);
    $this->withHeaders(platformTaskApiHeaders($key))->getJson('/api/platform/tasks/v1/tasks')->assertUnauthorized();

    config(['tasks.platform_api.key_hash' => 'not-a-sha256-digest']);
    $this->withHeaders(platformTaskApiHeaders($key))->getJson('/api/platform/tasks/v1/tasks')->assertUnauthorized();
});

it('uses the configured key without accepting a bearer token as a substitute', function (): void {
    [$key] = configurePlatformTaskApi();

    $this->withToken($key)->getJson('/api/platform/tasks/v1/tasks')->assertUnauthorized();
    $this->withHeaders(platformTaskApiHeaders($key))->getJson('/api/platform/tasks/v1/tasks')->assertOk();
});

it('does not disclose task existence before authenticating the request', function (): void {
    [$key, , $actor] = configurePlatformTaskApi();
    $task = createPlatformApiTask($actor);

    $this->getJson("/api/platform/tasks/v1/tasks/{$task->id}")->assertUnauthorized();
    $this->getJson('/api/platform/tasks/v1/tasks/999999')->assertUnauthorized();
    $this->withHeaders(platformTaskApiHeaders($key))
        ->getJson('/api/platform/tasks/v1/tasks/999999')
        ->assertNotFound();
});

it('rejects a missing canonical actor without authenticating another user', function (): void {
    [$key] = configurePlatformTaskApi();
    config(['tasks.platform_api.actor_email' => 'missing@example.test']);

    $this->actingAs(User::factory()->create());

    $this->withHeaders(platformTaskApiHeaders($key))
        ->getJson('/api/platform/tasks/v1/tasks')
        ->assertForbidden();
});

it('requires an active canonical actor and active platform administrator account', function (array $account, array $user): void {
    [$key] = configurePlatformTaskApi($account, $user);

    $this->withHeaders(platformTaskApiHeaders($key))->getJson('/api/platform/tasks/v1/tasks')->assertForbidden();
})->with([
    'not a platform administrator' => [['is_platform_admin' => false], []],
    'inactive account' => [['status' => 'inactive'], []],
    'inactive user' => [[], ['status' => 'suspended']],
]);

it('revokes access immediately when the actor account is disabled', function (): void {
    [$key, $account] = configurePlatformTaskApi();

    $this->withHeaders(platformTaskApiHeaders($key))->getJson('/api/platform/tasks/v1/tasks')->assertOk();
    $account->update(['status' => 'inactive']);
    $this->withHeaders(platformTaskApiHeaders($key))->getJson('/api/platform/tasks/v1/tasks')->assertForbidden();
});

it('creates reads updates moves and audits tasks through package actions', function (): void {
    [$key, , $actor] = configurePlatformTaskApi();
    $type = TaskType::query()->create(['name' => 'Feature', 'slug' => 'feature']);
    $tag = TaskTag::query()->create(['name' => 'API', 'slug' => 'api']);
    $headers = platformTaskApiHeaders($key);

    $created = $this->withHeaders($headers)->postJson('/api/platform/tasks/v1/tasks', [
        'title' => 'Export task API',
        'description' => 'Expose the safe automation surface.',
        'task_type_id' => $type->id,
        'priority' => 'urgent',
        'status' => 'backlog',
        'tag_ids' => [$tag->id],
    ])->assertCreated()
        ->assertJsonPath('data.title', 'Export task API')
        ->assertJsonPath('data.created_by', $actor->id);

    $taskId = $created->json('data.id');
    $this->withHeaders($headers)->getJson("/api/platform/tasks/v1/tasks/{$taskId}")
        ->assertOk()->assertJsonPath('data.tags.0.id', $tag->id);
    $this->withHeaders($headers)->patchJson("/api/platform/tasks/v1/tasks/{$taskId}", [
        'title' => 'Export safe task API',
        'assignee_id' => $actor->id,
    ])->assertOk()->assertJsonPath('data.assignee_id', $actor->id);
    $this->withHeaders($headers)->putJson("/api/platform/tasks/v1/tasks/{$taskId}/position", [
        'status' => 'in_progress',
        'position' => 1,
    ])->assertOk()->assertJsonPath('data.status', 'in_progress');

    expect(TaskChange::query()->where('task_id', $taskId)->pluck('operation')->all())
        ->toContain('created', 'assigned', 'updated', 'status_changed')
        ->and(TaskStatusChange::query()->where('task_id', $taskId)->count())->toBe(2);
});

it('validates the complete patch before assigning or writing audit evidence', function (): void {
    [$key, , $actor] = configurePlatformTaskApi();
    $task = createPlatformApiTask($actor);
    $before = $task->only(['title', 'assignee_id', 'due_date']);
    $updatedAt = $task->getRawOriginal('updated_at');

    $this->withHeaders(platformTaskApiHeaders($key))
        ->patchJson("/api/platform/tasks/v1/tasks/{$task->id}", [
            'assignee_id' => $actor->id,
            'due_date' => 'not-a-date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('due_date');

    expect($task->fresh()->only(array_keys($before)))->toBe($before)
        ->and($task->fresh()->getRawOriginal('updated_at'))->toBe($updatedAt)
        ->and(TaskChange::query()->where('task_id', $task->id)->count())->toBe(0)
        ->and(TaskStatusChange::query()->where('task_id', $task->id)->count())->toBe(0);
});

it('lists comments and resolves a task idempotently', function (): void {
    [$key, , $actor] = configurePlatformTaskApi();
    createPlatformApiTask($actor, ['title' => 'Already done', 'status' => 'done', 'board_position' => 4, 'completed_at' => now()]);
    $task = createPlatformApiTask($actor);
    $headers = platformTaskApiHeaders($key);

    $this->withHeaders($headers)->postJson("/api/platform/tasks/v1/tasks/{$task->id}/comments", ['body' => 'Reproduced.'])
        ->assertCreated()->assertJsonPath('data.author.id', $actor->id);
    $this->withHeaders($headers)->getJson("/api/platform/tasks/v1/tasks/{$task->id}/comments")
        ->assertOk()->assertJsonPath('data.0.body', 'Reproduced.');

    $this->withHeaders($headers)->postJson("/api/platform/tasks/v1/tasks/{$task->id}/resolve")
        ->assertOk()
        ->assertJsonPath('data.status', 'done')
        ->assertJsonPath('data.board_position', 2);
    $auditCount = TaskChange::query()->where('task_id', $task->id)->count();
    $statusCount = TaskStatusChange::query()->where('task_id', $task->id)->count();

    $this->withHeaders($headers)->postJson("/api/platform/tasks/v1/tasks/{$task->id}/resolve")
        ->assertOk()->assertJsonPath('data.status', 'done');

    expect(TaskComment::query()->where('task_id', $task->id)->count())->toBe(1)
        ->and(TaskChange::query()->where('task_id', $task->id)->count())->toBe($auditCount)
        ->and(TaskStatusChange::query()->where('task_id', $task->id)->count())->toBe($statusCount);
});

it('validates filters and pagination and returns stable ordered metadata', function (): void {
    [$key, , $actor] = configurePlatformTaskApi();
    $headers = platformTaskApiHeaders($key);
    $type = TaskType::query()->create(['name' => 'Bug', 'slug' => 'bug', 'sort_order' => 2]);
    TaskTag::query()->create(['name' => 'Backend', 'slug' => 'backend']);
    $older = createPlatformApiTask($actor, ['task_type_id' => $type->id, 'title' => 'Older', 'priority' => 'low']);
    $newer = createPlatformApiTask($actor, ['task_type_id' => $type->id, 'title' => 'Newer', 'priority' => 'high']);
    $older->update(['updated_at' => now()->subMinute()]);

    $this->withHeaders($headers)->getJson('/api/platform/tasks/v1/tasks?priority=high&per_page=1')
        ->assertOk()->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('meta.per_page', 1);
    $this->withHeaders($headers)->getJson('/api/platform/tasks/v1/tasks?per_page=101')->assertUnprocessable();
    $this->withHeaders($headers)->getJson('/api/platform/tasks/v1/tasks?status=invalid')->assertUnprocessable();
    $this->withHeaders($headers)->getJson('/api/platform/tasks/v1/meta')
        ->assertOk()
        ->assertJsonPath('data.types.0.id', $type->id)
        ->assertJsonPath('data.tags.0.slug', 'backend')
        ->assertJsonPath('data.assignees.0.id', $actor->id)
        ->assertJsonPath('data.statuses.4.value', 'done');
});

it('returns explicit type and tag shapes without package internals', function (): void {
    [$key, , $actor] = configurePlatformTaskApi();
    $tag = TaskTag::query()->create(['name' => 'Security', 'slug' => 'security']);
    $task = createPlatformApiTask($actor);
    $task->tags()->attach($tag);

    $response = $this->withHeaders(platformTaskApiHeaders($key))
        ->getJson("/api/platform/tasks/v1/tasks/{$task->id}")
        ->assertOk();

    expect(array_keys($response->json('data.type')))->toBe(['id', 'name', 'slug', 'color'])
        ->and(array_keys($response->json('data.tags.0')))->toBe(['id', 'name', 'slug', 'color']);
});

it('rejects invalid writes without creating tasks or audit evidence', function (): void {
    [$key] = configurePlatformTaskApi();

    $this->withHeaders(platformTaskApiHeaders($key))->postJson('/api/platform/tasks/v1/tasks', [
        'title' => '',
        'task_type_id' => 999999,
        'priority' => 'critical',
        'status' => 'done',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'task_type_id', 'priority', 'status']);

    expect(Task::query()->count())->toBe(0)
        ->and(TaskChange::query()->count())->toBe(0)
        ->and(TaskStatusChange::query()->count())->toBe(0);
});

it('does not expose destructive package routes', function (): void {
    [$key, , $actor] = configurePlatformTaskApi();
    $task = createPlatformApiTask($actor);
    $headers = platformTaskApiHeaders($key);

    $this->withHeaders($headers)->deleteJson("/api/platform/tasks/v1/tasks/{$task->id}")->assertMethodNotAllowed();
    $this->withHeaders($headers)->postJson("/api/platform/tasks/v1/tasks/{$task->id}/restore")->assertNotFound();
    $this->withHeaders($headers)->getJson("/api/platform/tasks/v1/tasks/{$task->id}/attachments/1")->assertNotFound();
});

it('applies the dedicated task API rate limiter', function (): void {
    $route = app('router')->getRoutes()->getByName('api.platform.tasks.v1.tasks.index');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:tasks-api');
});

it('rate limits requests from the same source including failed authentication', function (): void {
    configurePlatformTaskApi();

    for ($attempt = 1; $attempt <= 60; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
            ->getJson('/api/platform/tasks/v1/tasks')
            ->assertUnauthorized();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
        ->getJson('/api/platform/tasks/v1/tasks')
        ->assertTooManyRequests();
});
