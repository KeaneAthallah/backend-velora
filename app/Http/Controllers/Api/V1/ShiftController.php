<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'shifts.view', $businessId);
        return $this->success(RegisterShift::where('business_id', $businessId)->with('register', 'cashier')->paginate(50));
    }

    public function open(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        $this->authz->requirePermission($request->user(), 'shifts.manage', $businessId);
        $data = $request->validate([
            'cash_register_id' => 'required|integer|exists:cash_registers,id',
            'opening_cash' => 'required|numeric|min:0',
            'outlet_id' => 'nullable|integer',
        ]);
        if (! $outletId && ! empty($data['outlet_id'])) $outletId = $data['outlet_id'];
        if (! $outletId) return $this->error('Outlet required', 422);
        // prevent duplicate open shift for same register
        $existing = RegisterShift::where('cash_register_id', $data['cash_register_id'])->where('status', 'open')->first();
        if ($existing) return $this->error('Register already has open shift', 422);
        $shift = RegisterShift::create([
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'cash_register_id' => $data['cash_register_id'],
            'cashier_id' => $request->user()->id,
            'status' => 'open',
            'opening_cash' => $data['opening_cash'],
            'expected_cash' => $data['opening_cash'],
            'opened_at' => now(),
        ]);
        return $this->success($shift, 'Opened', 201);
    }

    public function close(Request $request, RegisterShift $shift)
    {
        $businessId = $request->attributes->get('business_id');
        if ($shift->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'shifts.manage', $businessId);
        if ($shift->status !== 'open') return $this->error('Shift not open', 422);
        $data = $request->validate(['actual_cash' => 'required|numeric|min:0', 'notes' => 'nullable|string']);
        DB::transaction(function () use ($shift, $data, $request) {
            // expected cash = opening + cash sales - cash movements out + in
            $cashSales = Sale::where('shift_id', $shift->id)->where('status', 'completed')->sum('total'); // simplified; should filter cash payments
            // better: sum payments method cash for sales in shift
            $cashPaid = \App\Models\Payment::whereHas('sale', fn ($q) => $q->where('shift_id', $shift->id))
                ->where('method', 'cash')->where('status', 'completed')->sum('amount');
            $movementsIn = \App\Models\CashMovement::where('register_shift_id', $shift->id)->where('type', 'in')->sum('amount');
            $movementsOut = \App\Models\CashMovement::where('register_shift_id', $shift->id)->where('type', 'out')->sum('amount');
            $expected = bcadd(bcadd((string) $shift->opening_cash, (string) $cashPaid, 4), (string) $movementsIn, 4);
            $expected = bcsub($expected, (string) $movementsOut, 4);
            $shift->expected_cash = $expected;
            $shift->actual_cash = $data['actual_cash'];
            $shift->difference = bcsub((string) $data['actual_cash'], $expected, 4);
            $shift->status = 'closed';
            $shift->closed_at = now();
            $shift->closed_by = $request->user()->id;
            $shift->notes = $data['notes'] ?? $shift->notes;
            $shift->save();
        });
        return $this->success($shift);
    }

    public function cashMovement(Request $request, RegisterShift $shift)
    {
        $businessId = $request->attributes->get('business_id');
        if ($shift->business_id !== $businessId) return $this->error('Not found', 404);
        if ($shift->status !== 'open') return $this->error('Shift not open', 422);
        $data = $request->validate(['type' => 'required|in:in,out', 'amount' => 'required|numeric|min:0.0001', 'reason' => 'nullable|string']);
        $cm = \App\Models\CashMovement::create([
            'register_shift_id' => $shift->id,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? null,
            'actor_id' => $request->user()->id,
        ]);
        return $this->success($cm, 'Recorded', 201);
    }
}
