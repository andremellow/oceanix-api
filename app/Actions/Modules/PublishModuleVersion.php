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
        return DB::transaction(function () use ($version, $actor, $restartInProgress): ModuleVersion {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            $version = ModuleVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($authorized === null || ! $version->is_shared || $version->company_id !== null || ! $version->isEditable() || $version->lineage_archived_at !== null) {
                throw new LogicException('Only a draft shared module version can be published.');
            }
            $version->questions()->lockForUpdate()->get();
            $version->questions()->with('options')->get()->each(fn ($question) => $question->options()->lockForUpdate()->get());
            $version->videos()->lockForUpdate()->get();
            $problems = $this->validator->problems($version->fresh());
            if ($problems !== []) {
                throw new LogicException(implode(' ', $problems));
            }
            $previous = ModuleVersion::query()
                ->where('lineage_uuid', $version->lineage_uuid)
                ->where('status', ModuleVersionStatus::Published->value)
                ->lockForUpdate()
                ->first();

            $version->update([
                'status' => ModuleVersionStatus::Published,
                'published_at' => now(),
                'published_by_account_id' => $authorized->id,
            ]);
            $previous?->update(['status' => ModuleVersionStatus::Retired]);

            if ($previous !== null) {
                $this->dispatchPropagation->handle($previous, $version, $authorized, $restartInProgress);
            }

            return $version->refresh();
        }, 3);
    }
}
