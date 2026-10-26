<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load('businessMemberships.business', 'businessMemberships.role', 'outletMemberships.outlet');
        return $this->success($user);
    }
}
