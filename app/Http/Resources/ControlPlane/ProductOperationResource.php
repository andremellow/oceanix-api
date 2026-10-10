<?php

namespace App\Http\Resources\ControlPlane;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductOperationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return $this->resource->response;
    }
}
