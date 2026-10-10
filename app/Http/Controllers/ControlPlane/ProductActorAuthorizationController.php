<?php

namespace App\Http\Controllers\ControlPlane;

use App\Actions\ControlPlane\AuthorizeProductActor;
use App\Http\Requests\ControlPlane\ProductActorAuthorizationRequest;
use Illuminate\Http\JsonResponse;

class ProductActorAuthorizationController
{
    public function __invoke(ProductActorAuthorizationRequest $request, AuthorizeProductActor $authorize): JsonResponse
    {
        return response()->json(['actor_assertion' => $authorize->handle($request->attributes->get('provisioning_principal'), $request->validated())]);
    }
}
