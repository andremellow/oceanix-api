<?php

namespace App\Actions\Modules;

use App\Models\Account;
use App\Models\ModuleVersion;
use App\Services\Modules\ModuleLineageLock;
use App\Services\Modules\SharedModuleDraftWriter;
use Illuminate\Support\Facades\DB;
use LogicException;

class SaveSharedModuleEditorDraft
{
    public function __construct(private readonly SharedModuleDraftWriter $writer, private readonly ModuleLineageLock $lineageLock) {}

    public function handle(ModuleVersion $version, Account $actor, array $payload, string $expectedRevision): string
    {
        return DB::transaction(function () use ($version, $actor, $payload, $expectedRevision): string {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            if ($authorized === null) {
                throw new LogicException('Only an active platform administrator can edit shared content.');
            }
            $lineage = $this->lineageLock->versions([$version->id]);
            $locked = $lineage->firstWhere('id', $version->id)
                ?? throw new LogicException('The module is unavailable.');
            if ($locked->lineage_archived_at !== null) {
                throw new LogicException('Archived shared module lineages cannot be edited.');
            }
            $prepared = $this->writer->prepare($locked, $payload, $expectedRevision);
            $rootId = $locked->source_lesson_id ?: $locked->id;
            $root = $lineage->firstWhere('id', $rootId)
                ?? throw new LogicException('The module root is unavailable.');
            if (! $root->is_shared || $root->company_id !== null || $root->lineage_uuid !== $locked->lineage_uuid) {
                throw new LogicException('Only platform-owned shared module drafts can be saved.');
            }
            $this->writer->write($prepared);
            if ($root->id !== $locked->id) {
                $root->fill([
                    'title' => trim($prepared['data']['title']),
                    'description' => $prepared['data']['description'],
                ]);
                if ($root->isDirty()) {
                    $root->save();
                }
            }

            return $this->writer->revision($locked->fresh());
        });
    }
}
