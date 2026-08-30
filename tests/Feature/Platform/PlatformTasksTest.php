<?php

use Andremellow\Tasks\Models\Task;
use Andremellow\Tasks\Models\TaskType;
use App\Models\Account;
use App\Models\PlatformTaskUser;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

it('lets a platform administrator open tasks from platform navigation', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $user = User::factory()->create(['account_id' => $account]);

    $this->actingAs($user)
        ->get(route('platform.tasks.index'))
        ->assertOk()
        ->assertSee(__('Task management'))
        ->assertSee(route('platform.tasks.index'), escape: false);
});

it('denies tasks to a company administrator and direct unauthenticated access', function (): void {
    $this->actingAs(adminUser())
        ->get(route('platform.tasks.index'))
        ->assertForbidden();

    auth()->logout();

    $this->get(route('platform.tasks.index'))
        ->assertRedirect(route('login'));
});

it('offers only active platform administrators as task assignees', function (): void {
    $platformAccount = Account::factory()->platformAdmin()->create();
    $platformUser = User::factory()->create(['account_id' => $platformAccount]);
    User::factory()->create();
    $inactiveAccount = Account::factory()->platformAdmin()->create(['status' => 'suspended']);
    User::factory()->create(['account_id' => $inactiveAccount]);

    expect(PlatformTaskUser::query()->pluck('id')->all())->toBe([$platformUser->id])
        ->and(Gate::forUser($platformUser)->allows('tasks.access'))->toBeTrue();
});

it('lets a platform administrator create a task', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $user = User::factory()->create(['account_id' => $account]);
    $type = TaskType::query()->create(['name' => 'Operations', 'slug' => 'operations']);

    Livewire::actingAs($user)
        ->test('tasks::tasks.index')
        ->set('title', 'Review platform rollout')
        ->set('newTypeId', $type->id)
        ->call('create')
        ->assertHasNoErrors();

    expect(Task::query()->sole()->created_by)->toBe($user->id);
});
