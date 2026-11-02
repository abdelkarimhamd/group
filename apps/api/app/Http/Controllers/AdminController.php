<?php

namespace App\Http\Controllers;

use App\Modules\Identity\Models\AdminUser;
use App\Support\Actor;
use Illuminate\Http\Request;

/**
 * Base for admin endpoints, which run behind auth:admin and a permission check.
 */
abstract class AdminController extends Controller
{
    protected function admin(Request $request): AdminUser
    {
        return $request->user();
    }

    protected function actor(Request $request): Actor
    {
        return Actor::admin($this->admin($request)->id);
    }
}
