<?php

namespace App\Http\Resources\ControlPlane;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyComplianceAccessResource extends JsonResource
{
    public static $wrap = null;

    public function withResponse(Request $request, $response): void
    {
        $response->setStatusCode(200);
    }

    public function toArray(Request $request): array
    {
        return $this->resource->response;
    }
}
