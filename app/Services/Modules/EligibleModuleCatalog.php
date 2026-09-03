<?php

namespace App\Services\Modules;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Module;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class EligibleModuleCatalog
{
    /** @return array{company: Collection<int, Module>, shared: Collection<int, Module>} */
    public function forCourseEditor(Company $company, User $actor, ?string $search = null): array
    {
        $base = Module::query()
            ->where('status', 'published')
            ->whereNull('lineage_archived_at')
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', '%'.trim((string) $search).'%')
                ->orWhere('code', 'like', '%'.trim((string) $search).'%')))
            ->orderBy('title');

        $companyModules = (clone $base)
            ->companyOwned($company)
            ->get();

        $canUseShared = $actor->isAdmin() || $actor->roles()
            ->withoutGlobalScopes()
            ->whereNull('archived_at')
            ->whereHas('permissions', fn ($query) => $query->where('key', Permission::SharedModulesUse->value))
            ->exists();

        $sharedModules = $canUseShared
            ? (clone $base)->shared()->get()
            : new Collection;

        return ['company' => $companyModules, 'shared' => $sharedModules];
    }
}
