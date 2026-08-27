<?php

namespace App\Actions\Modules;

use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\Module;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class CreateModule
{
    public function handle(Account $actor, string $code, string $title, ?string $description = null): Module
    {
        if (! $actor->is_platform_admin) {
            throw new LogicException('Only a platform administrator can create shared content.');
        }

        return DB::transaction(function () use ($actor, $code, $title, $description): Module {
            return Module::query()->create([
                'company_id' => null,
                'is_shared' => true,
                'code' => strtoupper(trim($code)),
                'lineage_uuid' => (string) Str::uuid(),
                'version_number' => 1,
                'title' => trim($title),
                'description' => $description,
                'status' => ModuleVersionStatus::Draft,
                'type' => 'video',
                'position' => 1,
                'is_required' => true,
                'minimum_watch_percentage' => 90,
                'passing_score' => 70,
                'published_by_account_id' => $actor->id,
            ]);
        });
    }
}
