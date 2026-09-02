<?php

use App\Http\Controllers\Api\Platform\TaskApiController;
use App\Http\Middleware\EnsurePlatformTaskApiAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('platform/tasks/v1')
    ->name('api.platform.tasks.v1.')
    ->middleware(['throttle:tasks-api', EnsurePlatformTaskApiAccess::class])
    ->group(function (): void {
        Route::get('/tasks', [TaskApiController::class, 'index'])->name('tasks.index');
        Route::get('/tasks/{task}', [TaskApiController::class, 'show'])->name('tasks.show');
        Route::post('/tasks', [TaskApiController::class, 'store'])->name('tasks.store');
        Route::patch('/tasks/{task}', [TaskApiController::class, 'update'])->name('tasks.update');
        Route::put('/tasks/{task}/position', [TaskApiController::class, 'move'])->name('tasks.move');
        Route::get('/tasks/{task}/comments', [TaskApiController::class, 'comments'])->name('tasks.comments.index');
        Route::post('/tasks/{task}/comments', [TaskApiController::class, 'storeComment'])
            ->name('tasks.comments.store');
        Route::post('/tasks/{task}/resolve', [TaskApiController::class, 'resolve'])
            ->name('tasks.resolve');
        Route::get('/meta', [TaskApiController::class, 'meta'])->name('meta');
    });
