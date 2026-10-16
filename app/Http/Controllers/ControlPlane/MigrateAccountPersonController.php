<?php

namespace App\Http\Controllers\ControlPlane;

use App\Actions\ControlPlane\MigrateAccountPerson;
use App\Http\Requests\ControlPlane\MigrateAccountPersonRequest;
use Illuminate\Http\JsonResponse;

class MigrateAccountPersonController
{
    public function __invoke(MigrateAccountPersonRequest $request, MigrateAccountPerson $action): JsonResponse
    {
        return response()->json($action->handle($request->attributes->get('provisioning_principal'), $request->validated()));
    }
}
