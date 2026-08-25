<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Certificate;
use App\Models\User;
use App\Services\Organization\ManagedPeopleScope;

class CertificatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::CertificatesView);
    }

    public function view(User $user, Certificate $certificate): bool
    {
        return $certificate->user_id === $user->id
            || ($user->hasPermission(Permission::CertificatesView)
                && app(ManagedPeopleScope::class)->canView($user, $certificate->user_id));
    }

    /** Downloading the PDF follows the same boundary as viewing the record. */
    public function download(User $user, Certificate $certificate): bool
    {
        return $this->view($user, $certificate);
    }

    public function revoke(User $user, Certificate $certificate): bool
    {
        return ! $certificate->isRevoked()
            && $user->hasPermission(Permission::CertificatesRevoke)
            && app(ManagedPeopleScope::class)->canView($user, $certificate->user_id);
    }
}
