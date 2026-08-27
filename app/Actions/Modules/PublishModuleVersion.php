<?php

namespace App\Actions\Modules;

use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\ModuleVersion;
use App\Services\Modules\ModuleVersionValidator;
use Illuminate\Support\Facades\DB;
use LogicException;

class PublishModuleVersion
{
    public function __construct(
        private readonly ModuleVersionValidator $validator,
        private readonly DispatchModulePropagation $dispatchPropagation,
    ) {}

    public function handle(ModuleVersion $version, Account $actor, bool $restartInProgress = false): ModuleVersion
    {
        if (! $actor->is_platform_admin || ! $version->is_shared || ! $version->isEditable()) {
            throw new LogicException('Only a draft shared module version can be published.');
        }

        $problems = $this->validator->problems($version);
        if ($problems !== []) {
            throw new LogicException(implode(' ', $problems));
        }

        return DB::transaction(function () use ($version, $actor, $restartInProgress): ModuleVersion {
            $previous = ModuleVersion::query()
                ->where('lineage_uuid', $version->lineage_uuid)
                ->where('status', ModuleVersionStatus::Published->value)
                ->lockForUpdate()
                ->first();

            $version->update([
                'status' => ModuleVersionStatus::Published,
                'published_at' => now(),
                'published_by_account_id' => $actor->id,
            ]);
            $previous?->update(['status' => ModuleVersionStatus::Retired]);

            if ($previous !== null) {
                $this->dispatchPropagation->handle($previous, $version, $actor, $restartInProgress);
            }

            return $version->refresh();
        });
    }
}
