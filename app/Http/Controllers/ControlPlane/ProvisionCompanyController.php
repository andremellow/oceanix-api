<?php

namespace App\Http\Controllers\ControlPlane;

use App\Actions\ControlPlane\EnsureCompany;
use App\Http\Requests\ControlPlane\ProvisionCompanyRequest;
use App\Http\Resources\ControlPlane\ProvisionedCompanyResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProvisionCompanyController
{
    public function __invoke(ProvisionCompanyRequest $request, EnsureCompany $ensure): JsonResponse
    {
        try {
            $receipt = $ensure->handle($request->attributes->get('provisioning_principal'), $request->validated());

            return response()->json((new ProvisionedCompanyResource($receipt->response))->resolve($request), $receipt->response_status);
        } catch (HttpExceptionInterface $error) {
            return response()->json(['error' => $error->getStatusCode() === 409 ? 'company_conflict' : 'provisioning_denied', 'message' => $error->getStatusCode() === 409 ? 'Company identity or operation input conflicts with an existing record.' : 'Provisioning access could not be confirmed.', 'correlation_id' => $request->validated('correlation_id')], $error->getStatusCode());
        } catch (Throwable) {
            return response()->json(['error' => 'provisioning_unavailable', 'message' => 'Provisioning is temporarily unavailable.', 'correlation_id' => $request->validated('correlation_id')], 503);
        }
    }
}
