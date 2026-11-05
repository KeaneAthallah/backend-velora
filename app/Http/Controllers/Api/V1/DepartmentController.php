<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'hr.view', $businessId);

        return $this->success(Department::where('business_id', $businessId)->get());
    }
}
