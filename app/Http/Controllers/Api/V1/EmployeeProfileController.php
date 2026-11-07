<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class EmployeeProfileController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'hr.view', $businessId);

        return $this->success(EmployeeProfile::where('business_id', $businessId)->with('user', 'department', 'position')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'hr.manage', $businessId);
        $data = $request->validate([
            'user_id' => 'required|integer',
            'department_id' => 'nullable|integer',
            'position_id' => 'nullable|integer',
            'employee_number' => 'nullable|string|max:64',
            'start_date' => 'nullable|date',
            'employment_status' => 'nullable|string|max:24',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
            'emergency_contact_name' => 'nullable|string',
            'emergency_contact_phone' => 'nullable|string|max:32',
        ]);
        if (! User::where('id', $data['user_id'])->exists()) {
            return $this->error('Not found', 404);
        }
        if (! empty($data['department_id']) && ! Department::where('id', $data['department_id'])->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        if (! empty($data['position_id']) && ! Position::where('id', $data['position_id'])->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        $profile = EmployeeProfile::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($profile, 'Profile created', 201);
    }

    public function update(Request $request, EmployeeProfile $employeeProfile)
    {
        $businessId = $request->attributes->get('business_id');
        if ($employeeProfile->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'hr.manage', $businessId);
        $employeeProfile->update($request->only(['department_id', 'position_id', 'employee_number', 'start_date', 'end_date', 'employment_status', 'phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'documents', 'metadata']));

        return $this->success($employeeProfile);
    }
}
