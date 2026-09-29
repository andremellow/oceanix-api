<?php

namespace App\Http\Controllers\ControlPlane;

use App\Actions\ControlPlane\ChangeCompanyComplianceAccess;
use App\Http\Requests\ControlPlane\CompanyComplianceAccessRequest;
use App\Http\Resources\ControlPlane\CompanyComplianceAccessResource;

class CompanyComplianceAccessController
{
    public function __invoke(CompanyComplianceAccessRequest $request, ChangeCompanyComplianceAccess $action): CompanyComplianceAccessResource
    {
        return new CompanyComplianceAccessResource($action->handle($request->attributes->get('provisioning_principal'), $request->command()));
    }
}
