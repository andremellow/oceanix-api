<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class ServicePrincipal extends Model
{
    use HasApiTokens;

    protected $fillable = ['name', 'product', 'environment', 'active', 'capabilities', 'company_uuids', 'expires_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'capabilities' => 'array', 'company_uuids' => 'array', 'expires_at' => 'datetime'];
    }
}
