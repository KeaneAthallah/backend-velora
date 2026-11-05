<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveCategory;
use App\Models\LeaveRequest;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class LeaveRequestController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function categories(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'leave.view', $businessId);

        return $this->success(LeaveCategory::where('business_id', $businessId)->get());
    }

    public function storeCategory(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'leave.manage', $businessId);
        $data = $request->validate(['name' => 'required|string|max:255', 'default_days' => 'nullable|integer|min:0', 'is_paid' => 'nullable|boolean']);
        $cat = LeaveCategory::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($cat, 'Category created', 201);
    }

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'leave.view', $businessId);
        $q = LeaveRequest::where('business_id', $businessId)->with('user', 'category', 'approver');
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', $request->input('user_id'));
        }

        return $this->success($q->orderByDesc('start_date')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'leave.view', $businessId);
        $data = $request->validate([
            'leave_category_id' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'days' => 'nullable|integer|min:1',
            'reason' => 'nullable|string',
        ]);
        $days = $data['days'] ?? (int) ((strtotime($data['end_date']) - strtotime($data['start_date'])) / 86400 + 1);
        $lr = LeaveRequest::create(array_merge($data, ['business_id' => $businessId, 'user_id' => $request->user()->id, 'days' => $days, 'status' => 'pending']));

        return $this->success($lr, 'Leave requested', 201);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $businessId = $request->attributes->get('business_id');
        if ($leaveRequest->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'leave.manage', $businessId);
        $data = $request->validate(['approval_notes' => 'nullable|string', 'status' => 'nullable|in:approved,rejected']);
        $status = $data['status'] ?? 'approved';
        $leaveRequest->update(['status' => $status, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'approval_notes' => $data['approval_notes'] ?? null]);

        return $this->success($leaveRequest);
    }
}
