<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'employees.view', $businessId);

        return $this->success(Permission::orderBy('name')->get());
    }
}
