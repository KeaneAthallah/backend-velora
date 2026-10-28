<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Promotion;
use App\Services\AuthorizationService;
use App\Services\SaleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected SaleService $saleService,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'promotions.view', $businessId);

        $query = Promotion::where('business_id', $businessId);

        if ($request->boolean('active_only')) {
            $query->live();
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('name', 'like', $term)->orWhere('code', 'like', $term);
            });
        }

        $promotions = $query->orderByDesc('is_active')->orderBy('name')->get();

        return $this->success($promotions);
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'promotions.manage', $businessId);

        $this->normalizeCode($request);

        $data = $request->validate($this->rules($businessId));

        if ($message = $this->percentageCap($data, 'percentage')) {
            return $this->error($message, 422, ['value' => [$message]], 'VALIDATION_ERROR');
        }

        $promotion = Promotion::create(array_merge($data, [
            'business_id' => $businessId,
            'usage_count' => 0,
            'type' => $data['type'] ?? 'percentage',
            'is_active' => $data['is_active'] ?? true,
        ]));

        return $this->success($promotion, 'Promotion created', 201);
    }

    public function update(Request $request, Promotion $promotion)
    {
        $businessId = $request->attributes->get('business_id');
        if ($promotion->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'promotions.manage', $businessId);

        $this->normalizeCode($request);

        $data = $request->validate($this->rules($businessId, $promotion->id, true));

        if ($message = $this->percentageCap($data, $promotion->type)) {
            return $this->error($message, 422, ['value' => [$message]], 'VALIDATION_ERROR');
        }

        $promotion->update($data);

        return $this->success($promotion->fresh());
    }

    public function destroy(Request $request, Promotion $promotion)
    {
        $businessId = $request->attributes->get('business_id');
        if ($promotion->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'promotions.manage', $businessId);

        if ($promotion->redemptions()->exists()) {
            return $this->error(
                'This promotion has been redeemed and cannot be deleted. Deactivate it instead.',
                422,
                [],
                'PROMOTION_IN_USE'
            );
        }

        $promotion->delete();

        return $this->success(null, 'Promotion deleted');
    }

    /**
     * Price a cart with a promotion/loyalty redemption without writing anything.
     */
    public function preview(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'sales.create', $businessId);

        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }
        if (! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        $data = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
            'promotion_code' => 'nullable|string|max:64',
            'redeem_points' => 'nullable|numeric|min:0',
            'tax_rate_id' => 'nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.option_ids' => 'nullable|array',
            'items.*.option_ids.*' => 'integer',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        try {
            $priced = $this->saleService->priceItems(
                $data['items'],
                $businessId,
                (int) $outletId,
                $data['tax_rate_id'] ?? null,
                [
                    'promotion_code' => $data['promotion_code'] ?? null,
                    'customer_id' => $data['customer_id'] ?? null,
                    'redeem_points' => (string) ($data['redeem_points'] ?? '0'),
                ],
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'PROMOTION_ERROR');
        }

        /** @var Promotion|null $promotion */
        $promotion = $priced['promotion'];

        return $this->success([
            'subtotal' => $priced['subtotal'],
            'discount_amount' => $priced['discount_amount'],
            'promotion' => $promotion ? [
                'id' => (string) $promotion->id,
                'name' => $promotion->name,
                'code' => $promotion->code,
                'type' => $promotion->type,
                'value' => (string) $promotion->value,
            ] : null,
            'promotion_discount' => $priced['promotion_discount'],
            'loyalty_discount' => $priced['loyalty_discount'],
            'loyalty_points_redeemed' => $priced['loyalty_points_redeemed'],
            'tax_amount' => $priced['tax_amount'],
            'total' => $priced['total'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(int $businessId, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:64',
                Rule::unique('promotions', 'code')->where('business_id', $businessId)->ignore($ignoreId),
            ],
            'description' => 'nullable|string|max:1000',
            'type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'value' => [$required, 'numeric', 'min:0'],
            'min_spend' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'per_customer_limit' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function percentageCap(array $data, string $fallbackType): ?string
    {
        $type = $data['type'] ?? $fallbackType;

        if ($type === 'percentage' && isset($data['value']) && bccomp((string) $data['value'], '100', 4) === 1) {
            return 'A percentage promotion cannot be more than 100%';
        }

        return null;
    }

    private function normalizeCode(Request $request): void
    {
        if (! $request->has('code') || $request->input('code') === null) {
            $request->merge(['code' => null]);

            return;
        }

        $code = strtoupper(trim((string) $request->input('code')));
        $request->merge(['code' => $code === '' ? null : $code]);
    }
}
