<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;

class PermissionController extends Controller
{
    public function index()
    {
        return $this->success(Permission::orderBy('name')->get());
    }
}
