<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuthorizationService;
use App\Services\SmartReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class ReorderController extends Controller
{
    public function __construct(
        private readonly SmartReorderService $smartReorder,
        private readonly AuthorizationService $authz,
    ) {}

    /**
     * Two switches, because the copilot's read and write halves landed apart.
     *
     * The forecast is read-only and works off the ingredients, recipes and sales
     * tables. Drafting purchase orders from it needs ingredient-based lines,
     * which the purchasing schema does not accept yet (`purchase_order_items`
     * is product-based), so that half stays behind its own flag.
     */
    private function forecastAvailable(): bool
    {
        return (bool) config('features.reorder_copilot', false);
    }

    private function purchaseOrdersAvailable(): bool
    {
        return (bool) config('features.reorder_purchase_orders', false);
    }

    private function notImplemented(Request $request, string $message): JsonResponse
    {
        $this->authz->requirePermission(
            $request->user(),
            'purchasing.manage',
            (int) $request->attributes->get('business_id'),
        );

        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => 'NOT_IMPLEMENTED',
        ], 501);
    }

    public function forecast(Request $request): JsonResponse
    {
        if (! $this->forecastAvailable()) {
            return $this->notImplemented($request, 'The reorder forecast is not available yet.');
        }

        $params = Validator::validate($request->all(), [
            // `from` is the start of the historical lookback, so it must be in
            // the past — the old `after:now` rule rejected every valid window.
            'from' => 'nullable|date|before_or_equal:until|before:now',
            'until' => 'nullable|date|after_or_equal:from|before_or_equal:now',
            'defaultLeadDays' => 'nullable|integer|min:1',
            'minCoverageDays' => 'nullable|integer|min:1',
            'forecastAt' => 'nullable|date|after:0 days ago',
            'models' => 'nullable|array',
            'models.demand' => 'nullable|boolean',
            'models.shelfLife' => 'nullable|boolean',
            'models.leadTime' => 'nullable|boolean',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->smartReorder->forecastForBusiness((int) $request->attributes->get('business_id'), $params),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        if (! $this->purchaseOrdersAvailable()) {
            return $this->notImplemented(
                $request,
                'Forecasting is available, but drafting purchase orders from it is not: '.
                'purchase-order lines are product-based until ingredient lines ship.',
            );
        }

        $params = Validator::validate($request->all(), [
            'from' => 'nullable|date|before_or_equal:until|before:now',
            'until' => 'nullable|date|after_or_equal:from|before_or_equal:now',
            'defaultLeadDays' => 'nullable|integer|min:1',
            'minCoverageDays' => 'nullable|integer|min:1',
            'forecastAt' => 'nullable|date|after:0 days ago',
            'models' => 'nullable|array',
            'models.demand' => 'nullable|boolean',
            'models.shelfLife' => 'nullable|boolean',
            'models.leadTime' => 'nullable|boolean',
            'supplierId' => 'nullable|integer|min:1',
            'budget' => 'nullable|numeric|min:0',
            'preferCurrentStockSupplier' => 'nullable|boolean',
        ]);

        $result = $this->smartReorder->suggestPurchaseOrder(
            (int) $request->attributes->get('business_id'),
            $params,
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function approve(Request $request, int $purchaseOrderItemId): JsonResponse
    {
        if (! $this->purchaseOrdersAvailable()) {
            return $this->notImplemented(
                $request,
                'Reordering into a purchase order is not available yet.',
            );
        }

        $params = Validator::validate($request->all(), [
            'quantity' => 'nullable|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $item = $this->smartReorder->approveItem(
            (int) $request->attributes->get('business_id'),
            $purchaseOrderItemId,
            $params,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (string) $item->id,
                'purchaseOrderId' => (string) $item->purchase_order_id,
                'productId' => (string) $item->product_id,
                'ingredientId' => (string) $item->ingredient_id,
                'quantity' => $item->quantity,
                'costPerUnit' => (float) $item->cost_per_unit,
                'estimatedTotal' => (float) $item->estimated_total,
                'status' => $item->status,
                'updatedAt' => $item->updated_at->toIso8601String(),
            ],
        ]);
    }

    public function reject(Request $request, int $purchaseOrderItemId): JsonResponse
    {
        if (! $this->purchaseOrdersAvailable()) {
            return $this->notImplemented(
                $request,
                'Reordering into a purchase order is not available yet.',
            );
        }

        $item = $this->smartReorder->rejectItem(
            (int) $request->attributes->get('business_id'),
            $purchaseOrderItemId,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (string) $item->id,
                'purchaseOrderId' => (string) $item->purchase_order_id,
                'productId' => (string) $item->product_id,
                'ingredientId' => (string) $item->ingredient_id,
                'status' => $item->status,
                'updatedAt' => $item->updated_at->toIso8601String(),
            ],
        ]);
    }
}
