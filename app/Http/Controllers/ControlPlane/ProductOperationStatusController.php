<?php

namespace App\Http\Controllers\ControlPlane;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Http\Resources\ControlPlane\ProductOperationResource;
use Illuminate\Http\Request;

class ProductOperationStatusController
{
    public function __invoke(Request $request, string $operation_uuid, ApplyProductOperation $apply): ProductOperationResource
    {
        return new ProductOperationResource($apply->receipt($request->attributes->get('provisioning_principal'), $operation_uuid));
    }
}
