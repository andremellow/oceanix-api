<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class ServicePrincipal extends Model
{
    use HasApiTokens;

    protected $fillable = ['name', 'product', 'environment', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
