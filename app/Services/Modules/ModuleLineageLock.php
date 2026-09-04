<?php

namespace App\Services\Modules;

use App\Models\ModuleVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ModuleLineageLock
{
    /** @param iterable<int> $versionIds
     * @return Collection<int, ModuleVersion>
     */
    public function versions(iterable $versionIds): Collection
    {
        $ids = collect($versionIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        // This unlocked read only plans the lock set. Every value is revalidated below.
        $planned = ModuleVersion::query()->withoutGlobalScopes()->whereKey($ids)->get(['id', 'source_lesson_id', 'lineage_uuid']);
        if ($planned->count() !== $ids->count()) {
            throw new LogicException('One or more modules are unavailable.');
        }

        $lineages = $planned->pluck('lineage_uuid')->filter()->unique()->sort()->values();
        if (DB::getDriverName() === 'pgsql') {
            foreach ($lineages as $lineage) {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, ?))', [(string) $lineage, 9042026]);
            }
        }
        $locked = ModuleVersion::query()->withoutGlobalScopes()->whereIn('lineage_uuid', $lineages)->orderBy('id')->lockForUpdate()->get();
        $requested = $locked->whereIn('id', $ids)->keyBy('id');
        if ($requested->count() !== $ids->count()) {
            throw new LogicException('One or more modules changed while they were being locked.');
        }
        foreach ($planned as $candidate) {
            $current = $requested->get($candidate->id);
            if ($current->lineage_uuid !== $candidate->lineage_uuid) {
                throw new LogicException('A module lineage changed while it was being locked.');
            }
        }

        return $locked;
    }
}
