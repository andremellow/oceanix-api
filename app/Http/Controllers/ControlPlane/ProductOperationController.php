<?php

namespace App\Http\Controllers\ControlPlane;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Http\Requests\ControlPlane\ProductOperationRequest;
use App\Http\Resources\ControlPlane\ProductOperationResource;

class ProductOperationController
{
    public function __invoke(ProductOperationRequest $request, ApplyProductOperation $apply): ProductOperationResource
    {
        return new ProductOperationResource($apply->handle($request->attributes->get('provisioning_principal'), $request->validated()));
    }
}
