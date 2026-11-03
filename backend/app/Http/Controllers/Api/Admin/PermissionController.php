<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PermissionController extends Controller
{
    /** The permission catalogue grouped by module, used to render the role matrix. */
    public function index(): JsonResponse
    {
        Gate::authorize('roles.view');

        return response()->json(['data' => PermissionCatalog::grouped()]);
    }
}
