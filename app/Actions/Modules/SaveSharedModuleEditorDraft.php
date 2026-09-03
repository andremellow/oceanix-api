<?php

namespace App\Actions\Modules;

use App\Models\Account;
use App\Models\ModuleVersion;
use App\Services\Modules\SharedModuleDraftWriter;
use Illuminate\Support\Facades\DB;
use LogicException;

class SaveSharedModuleEditorDraft
{
    public function __construct(private readonly SharedModuleDraftWriter $writer) {}

    public function handle(ModuleVersion $version, Account $actor, array $payload, string $expectedRevision): string
    {
        return DB::transaction(function () use ($version, $actor, $payload, $expectedRevision): string {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            if ($authorized === null) {
                throw new LogicException('Only an active platform administrator can edit shared content.');
            }
            $locked = ModuleVersion::query()->lockForUpdate()->findOrFail($version->id);
            $prepared = $this->writer->prepare($locked, $payload, $expectedRevision);
            $this->writer->write($prepared);

            return $this->writer->revision($locked->fresh());
        });
    }
}
