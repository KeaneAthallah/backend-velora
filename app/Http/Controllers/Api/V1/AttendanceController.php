<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'attendance.view', $businessId);
        $q = Attendance::where('business_id', $businessId);
        if ($request->filled('user_id')) {
            $q->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('from')) {
            $q->where('check_in_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->where('check_in_at', '<=', $request->input('to'));
        }

        return $this->success($q->orderByDesc('check_in_at')->get());
    }

    public function checkIn(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'attendance.manage', $businessId);
        $data = $request->validate(['outlet_id' => 'nullable|integer', 'notes' => 'nullable|string']);
        $att = Attendance::create([
            'business_id' => $businessId,
            'user_id' => $request->user()->id,
            'outlet_id' => $data['outlet_id'] ?? $request->attributes->get('outlet_id'),
            'check_in_at' => now(),
            'status' => 'present',
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->success($att, 'Checked in', 201);
    }

    public function checkOut(Request $request, Attendance $attendance)
    {
        $businessId = $request->attributes->get('business_id');
        if ($attendance->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'attendance.manage', $businessId);
        if ($attendance->check_out_at) {
            return $this->error('Already checked out', 422);
        }
        $attendance->update(['check_out_at' => now()]);

        return $this->success($attendance);
    }
}
