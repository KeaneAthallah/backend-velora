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
     * The reorder copilot is scaffolded ahead of its data layer: its forecast
     * tables and several models do not exist yet. Until those ship, the
     * endpoints must not throw a 500. They enforce RBAC and then report that
     * the feature is unavailable.
     */
    private function reorderAvailable(): bool
    {
        return (bool) config('features.reorder_copilot', false);
    }

    private function notImplemented(Request $request): JsonResponse
    {
        $this->authz->requirePermission(
            $request->user(),
            'purchasing.manage',
            (int) $request->attributes->get('business_id'),
        );

        return response()->json([
            'success' => false,
            'message' => 'The reorder copilot is not available yet.',
            'code' => 'NOT_IMPLEMENTED',
        ], 501);
    }

    public function forecast(Request $request): JsonResponse
    {
        if (! $this->reorderAvailable()) {
            return $this->notImplemented($request);
        }

        $params = Validator::validate($request->all(), [
            'from' => 'nullable|date|before_or_equal:until|after:now',
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
        if (! $this->reorderAvailable()) {
            return $this->notImplemented($request);
        }

        $params = Validator::validate($request->all(), [
            'from' => 'nullable|date|before_or_equal:until|after:now',
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
        if (! $this->reorderAvailable()) {
            return $this->notImplemented($request);
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
        if (! $this->reorderAvailable()) {
            return $this->notImplemented($request);
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
