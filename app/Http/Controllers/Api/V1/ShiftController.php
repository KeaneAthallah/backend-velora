<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Payment;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'cash_register_id' => [
                'required',
                'integer',
                Rule::exists('cash_registers', 'id')->where('business_id', $businessId),
            ],
            'opening_cash' => 'required|numeric|min:0',
            'outlet_id' => [
                'nullable',
                'integer',
                Rule::exists('outlets', 'id')->where('business_id', $businessId),
            ],
        ]);
        if (! $outletId && ! empty($data['outlet_id'])) {
            $outletId = $data['outlet_id'];
        }
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }

        // A register belongs to one outlet; never open a shift against another
        // outlet's register (or another business's, handled by the rule above).
        $register = CashRegister::where('id', $data['cash_register_id'])
            ->where('business_id', $businessId)
            ->firstOrFail();
        if ((int) $register->outlet_id !== (int) $outletId) {
            return $this->error('Register does not belong to this outlet', 422, [], 'SHIFT_ERROR');
        }

        // prevent duplicate open shift for same register
        $existing = RegisterShift::where('cash_register_id', $data['cash_register_id'])
            ->where('business_id', $businessId)
            ->where('status', 'open')
            ->first();
        if ($existing) {
            return $this->error('Register already has open shift', 422);
        }
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
        if ($shift->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'shifts.manage', $businessId);
        if ($shift->status !== 'open') {
            return $this->error('Shift not open', 422);
        }
        $data = $request->validate(['actual_cash' => 'required|numeric|min:0', 'notes' => 'nullable|string']);
        DB::transaction(function () use ($shift, $data, $request) {
            // expected cash = opening + cash sales - cash movements out + in
            $cashSales = Sale::where('shift_id', $shift->id)->where('status', 'completed')->sum('total'); // simplified; should filter cash payments
            // better: sum payments method cash for sales in shift
            $cashPaid = Payment::whereHas('sale', fn ($q) => $q->where('shift_id', $shift->id))
                ->where('method', 'cash')->where('status', 'completed')->sum('amount');
            $movementsIn = CashMovement::where('register_shift_id', $shift->id)->where('type', 'in')->sum('amount');
            $movementsOut = CashMovement::where('register_shift_id', $shift->id)->where('type', 'out')->sum('amount');
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
        if ($shift->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'shifts.manage', $businessId);
        if ($shift->status !== 'open') {
            return $this->error('Shift not open', 422);
        }
        $data = $request->validate(['type' => 'required|in:in,out', 'amount' => 'required|numeric|min:0.0001', 'reason' => 'nullable|string']);
        $cm = CashMovement::create([
            'register_shift_id' => $shift->id,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? null,
            'actor_id' => $request->user()->id,
        ]);

        // Keep the drawer total in step while the shift is open; closing the
        // shift recomputes it from payments and movements anyway.
        $shift->expected_cash = $data['type'] === 'in'
            ? bcadd((string) $shift->expected_cash, (string) $data['amount'], 4)
            : bcsub((string) $shift->expected_cash, (string) $data['amount'], 4);
        $shift->save();

        return $this->success($cm, 'Recorded', 201);
    }

    public function cashMovements(Request $request, RegisterShift $shift)
    {
        $businessId = $request->attributes->get('business_id');
        if ($shift->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'shifts.view', $businessId);

        return $this->success(CashMovement::where('register_shift_id', $shift->id)
            ->orderBy('created_at', 'desc')
            ->paginate(50));
    }
}
