<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Every controller authorizes explicitly; the skeleton no longer provides this.
    use AuthorizesRequests;
}
