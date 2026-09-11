<?php

namespace App\Actions\Modules;

use App\Models\Account;
use App\Models\ModuleVersion;
use App\Services\CourseEditor\EditorRevision;
use App\Services\Modules\ModuleLineageLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ReorderSharedModuleAssessment
{
    public function __construct(private readonly ModuleLineageLock $lineageLock, private readonly EditorRevision $revisions) {}

    /** @param list<int> $orderedIds */
    public function handle(ModuleVersion $version, Account $actor, string $level, ?int $parentId, array $orderedIds, string $expectedRevision): void
    {
        $ids = array_values(array_map('intval', $orderedIds));
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['order' => __('The saved order is stale. Reload the draft and try again.')]);
        }

        DB::transaction(function () use ($version, $actor, $level, $parentId, $ids, $expectedRevision): void {
            if (! Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->exists()) {
                throw new LogicException('Only an active platform administrator can change shared content.');
            }
            $locked = $this->lineageLock->versions([$version->id])->firstWhere('id', $version->id)
                ?? throw new LogicException('The module is unavailable.');
            if (! $locked->isEditable() || ! $locked->is_shared || $locked->company_id !== null || $locked->lineage_archived_at !== null) {
                throw new LogicException('Only active platform-owned shared module drafts can be changed.');
            }
            $questions = $locked->questions()->with('options')->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->revisions->forSharedModule($locked, $questions), $expectedRevision)) {
                throw ValidationException::withMessages(['revision' => __('This module changed elsewhere. Reload the page before trying again.')]);
            }
            $siblings = match ($level) {
                'questions' => $questions,
                'options' => $locked->questions()->lockForUpdate()->findOrFail($parentId)->options()->orderBy('id')->lockForUpdate()->get(),
                default => throw ValidationException::withMessages(['order' => __('The saved order is stale. Reload the draft and try again.')]),
            };
            if ($siblings->pluck('id')->sort()->values()->all() !== collect($ids)->sort()->values()->all()) {
                throw ValidationException::withMessages(['order' => __('The saved order is stale. Reload the draft and try again.')]);
            }
            foreach ($ids as $index => $id) {
                $siblings->firstWhere('id', $id)->update(['position' => 1000000 + $index]);
            }
            foreach ($ids as $index => $id) {
                $siblings->firstWhere('id', $id)->update(['position' => $index + 1]);
            }
        }, 3);
    }
}
