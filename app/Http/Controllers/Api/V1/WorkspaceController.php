<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\AuthorizationService;
use App\Services\IndustryService;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected IndustryService $industries,
    ) {}

    public function show(Request $request)
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 422, 'Business context required');

        $this->authz->requirePermission($request->user(), 'businesses.view', $business->id);

        $payload = $this->industries->resolve($business);

        return $this->success($payload);
    }
}
