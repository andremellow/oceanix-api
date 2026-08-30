<?php

use App\Http\Middleware\EnsureUserIsPlatformAdmin;
use App\Models\PlatformTaskUser;

return [
    'user_model' => PlatformTaskUser::class,
    'ability' => 'tasks.access',
    'layout' => 'layouts.platform',

    'web' => [
        'enabled' => true,
        'prefix' => 'platform/tasks',
        'name' => 'platform.tasks.',
        'middleware' => ['web', 'auth', EnsureUserIsPlatformAdmin::class, 'tasks.access'],
    ],

    'api' => [
        'enabled' => false,
        'prefix' => 'api/tasks',
        'name' => 'api.tasks.',
        'middleware' => ['api', 'auth:sanctum', 'tasks.access'],
    ],

    'assignee_resolver' => null,
    'user_name_column' => 'name',
    'board' => ['done_limit' => 100],
    'description_max' => 100000,
    'attachment_max_kb' => 10240,
    'attachment_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt', 'md', 'csv', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods'],
    'media_disk' => null,
    'media_morph_alias' => null,
    'timezone' => 'UTC',
];
