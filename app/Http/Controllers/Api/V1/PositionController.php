<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'hr.view', $businessId);

        return $this->success(Position::where('business_id', $businessId)->get());
    }
}
