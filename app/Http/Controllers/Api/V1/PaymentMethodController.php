<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentMethodController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'payments.view', $businessId);

        // A business created before payment methods existed (or via a seeder)
        // still gets a usable set of rails the first time it asks for them.
        PaymentMethod::ensureDefaults($businessId);

        $query = PaymentMethod::where('business_id', $businessId);
        if ($request->boolean('active') || $request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return $this->success(
            $query->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'payments.manage', $businessId);
        PaymentMethod::ensureDefaults($businessId);

        $data = $request->validate([
            'code' => 'nullable|string|max:32',
            'name' => 'required|string|max:64',
            'type' => ['nullable', Rule::in(PaymentMethod::TYPES)],
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'settings' => 'nullable|array',
        ]);

        $code = $this->normalizeCode($data['code'] ?? $data['name']);
        if ($code === '') {
            return $this->error('Give the payment method a code', 422, ['code' => ['Invalid code']], 'VALIDATION_ERROR');
        }
        if (PaymentMethod::where('business_id', $businessId)->where('code', $code)->exists()) {
            return $this->error(
                'A payment method with this code already exists',
                422,
                ['code' => ['Already in use']],
                'VALIDATION_ERROR'
            );
        }

        $method = PaymentMethod::create([
            'business_id' => $businessId,
            'code' => $code,
            'name' => $data['name'],
            'type' => $data['type'] ?? 'other',
            'requires_reference' => $data['requires_reference'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
            'settings' => $data['settings'] ?? null,
        ]);

        return $this->success($method, 'Payment method created', 201);
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $businessId = $request->attributes->get('business_id');
        if ($paymentMethod->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payments.manage', $businessId);

        // Codes are immutable: recorded payments reference them for reporting.
        $data = $request->validate([
            'name' => 'sometimes|string|max:64',
            'type' => ['sometimes', Rule::in(PaymentMethod::TYPES)],
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'settings' => 'nullable|array',
        ]);

        $paymentMethod->update($data);

        return $this->success($paymentMethod->fresh());
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod)
    {
        $businessId = $request->attributes->get('business_id');
        if ($paymentMethod->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payments.manage', $businessId);

        $used = Payment::where('business_id', $businessId)
            ->where('method', $paymentMethod->code)
            ->exists();
        if ($used) {
            return $this->error(
                'This payment method has payments and cannot be deleted. Deactivate it instead.',
                422,
                [],
                'PAYMENT_METHOD_IN_USE'
            );
        }

        $paymentMethod->delete();

        return $this->success(null, 'Payment method deleted');
    }

    private function normalizeCode(string $value): string
    {
        $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($value)) ?? '', '_');

        return substr($code, 0, 32);
    }
}
