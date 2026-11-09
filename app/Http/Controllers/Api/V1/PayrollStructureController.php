<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessMembership;
use App\Models\PayrollStructure;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

/**
 * Salary structures: what each team member is paid before a run exists.
 * A structure is snapshotted into a payslip when a run is generated, so editing
 * or deleting one never rewrites an approved run.
 */
class PayrollStructureController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'payroll.view', $businessId);

        $structures = PayrollStructure::where('business_id', $businessId)
            ->with('user:id,name,email')
            ->get()
            ->sortBy(fn (PayrollStructure $structure) => $structure->user?->name ?? '')
            ->values();

        return $this->success($structures);
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'payroll.manage', $businessId);

        $data = $request->validate([
            'user_id' => 'required|integer',
            'type' => 'required|in:fixed,hourly',
            'base_salary' => 'nullable|integer|min:0',
            'hourly_rate' => 'nullable|integer|min:0',
            'allowances' => 'nullable|array',
            'deductions' => 'nullable|array',
        ]);

        $isMember = BusinessMembership::where('business_id', $businessId)
            ->where('user_id', $data['user_id'])
            ->where('status', 'active')
            ->exists();
        if (! $isMember) {
            return $this->error('That person is not an active member of this business.', 422);
        }

        if (PayrollStructure::where('business_id', $businessId)->where('user_id', $data['user_id'])->exists()) {
            return $this->error('This person already has a payroll structure. Edit it instead.', 422);
        }

        if ($error = $this->amountError($data['type'], $data['base_salary'] ?? 0, $data['hourly_rate'] ?? 0)) {
            return $this->error($error, 422);
        }

        $structure = PayrollStructure::create([
            'business_id' => $businessId,
            'user_id' => $data['user_id'],
            'type' => $data['type'],
            'base_salary' => $data['type'] === 'fixed' ? (int) ($data['base_salary'] ?? 0) : 0,
            'hourly_rate' => $data['type'] === 'hourly' ? (int) ($data['hourly_rate'] ?? 0) : 0,
            'allowances' => $data['allowances'] ?? null,
            'deductions' => $data['deductions'] ?? null,
        ]);

        return $this->success($structure->load('user:id,name,email'), 'Payroll structure created', 201);
    }

    public function update(Request $request, PayrollStructure $payrollStructure)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $payrollStructure->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payroll.manage', $businessId);

        $data = $request->validate([
            'type' => 'sometimes|in:fixed,hourly',
            'base_salary' => 'nullable|integer|min:0',
            'hourly_rate' => 'nullable|integer|min:0',
            'allowances' => 'nullable|array',
            'deductions' => 'nullable|array',
        ]);

        $type = $data['type'] ?? $payrollStructure->type;
        $base = (int) ($data['base_salary'] ?? $payrollStructure->base_salary);
        $rate = (int) ($data['hourly_rate'] ?? $payrollStructure->hourly_rate);
        if ($error = $this->amountError($type, $base, $rate)) {
            return $this->error($error, 422);
        }

        $payrollStructure->update([
            'type' => $type,
            'base_salary' => $type === 'fixed' ? $base : 0,
            'hourly_rate' => $type === 'hourly' ? $rate : 0,
            'allowances' => array_key_exists('allowances', $data) ? $data['allowances'] : $payrollStructure->allowances,
            'deductions' => array_key_exists('deductions', $data) ? $data['deductions'] : $payrollStructure->deductions,
        ]);

        return $this->success($payrollStructure->load('user:id,name,email'));
    }

    public function destroy(Request $request, PayrollStructure $payrollStructure)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $payrollStructure->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payroll.manage', $businessId);

        // Generated runs keep their snapshot, so existing payslips are untouched.
        $payrollStructure->delete();

        return $this->success(null, 'Deleted');
    }

    private function amountError(string $type, int $base, int $rate): ?string
    {
        if ($type === 'hourly' && $rate <= 0) {
            return 'An hourly structure needs an hourly rate.';
        }
        if ($type === 'fixed' && $base <= 0) {
            return 'A fixed structure needs a monthly salary.';
        }

        return null;
    }
}
