<?php

namespace App\Policies;

use App\Enums\ModuleVersionStatus;
use App\Enums\Permission;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\User;

class ModulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin()
            || $user->hasPermission(Permission::CoursesView)
            || $user->hasPermission(Permission::SharedModulesView);
    }

    public function view(User $user, Module $module): bool
    {
        if ($module->is_shared) {
            return $user->isPlatformAdmin()
                || $user->hasPermission(Permission::SharedModulesView);
        }

        return $this->belongsToUserCompany($user, $module)
            && $user->hasPermission(Permission::CoursesView);
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin()
            || $user->hasPermission(Permission::CoursesCreate);
    }

    public function update(User $user, Module $module): bool
    {
        return $this->canWrite($user, $module, Permission::CoursesUpdate);
    }

    public function updateVersion(User $user, ModuleVersion $version): bool
    {
        return $version->status === ModuleVersionStatus::Draft
            && $this->canWrite($user, $version->module, Permission::CoursesUpdate);
    }

    public function publish(User $user, Module $module): bool
    {
        return $this->canWrite($user, $module, Permission::CoursesPublish);
    }

    public function retire(User $user, Module $module): bool
    {
        return $this->canWrite($user, $module, Permission::CoursesRetire);
    }

    public function use(User $user, Module $module): bool
    {
        if ($module->is_shared) {
            return $user->hasPermission(Permission::SharedModulesUse);
        }

        return $this->belongsToUserCompany($user, $module)
            && $user->hasPermission(Permission::CoursesUpdate);
    }

    private function canWrite(User $user, Module $module, Permission $permission): bool
    {
        if ($module->is_shared) {
            return $user->isPlatformAdmin();
        }

        return $this->belongsToUserCompany($user, $module)
            && $user->hasPermission($permission);
    }

    private function belongsToUserCompany(User $user, Module $module): bool
    {
        return $module->company_id !== null
            && (int) $module->company_id === (int) $user->company_id;
    }
}
