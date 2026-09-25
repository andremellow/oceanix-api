<?php

namespace App\Http\Resources\ControlPlane;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProvisionedCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(['account_company_uuid', 'compliance_company_public_id', 'workos_organization_id', 'outcome', 'operation_id']));
    }
}
