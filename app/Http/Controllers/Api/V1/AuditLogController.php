<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'reports.view', $businessId);
        return $this->success(AuditLog::where('business_id', $businessId)->orderByDesc('id')->paginate(100));
    }
}
