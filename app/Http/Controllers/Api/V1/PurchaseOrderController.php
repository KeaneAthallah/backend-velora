<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'purchasing.view', $businessId);
        return $this->success(PurchaseOrder::where('business_id', $businessId)->with('items', 'supplier')->paginate(50));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        $this->authz->requirePermission($request->user(), 'purchasing.manage', $businessId);
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'outlet_id' => 'nullable|integer',
            'po_number' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'expected_at' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric',
        ]);
        if (! $outletId && ! empty($data['outlet_id'])) $outletId = $data['outlet_id'];
        if (! $outletId) return $this->error('Outlet required', 422);
        $poNumber = $data['po_number'] ?? ('PO-' . now()->format('Ymd') . '-' . uniqid());
        $po = DB::transaction(function () use ($businessId, $outletId, $data, $poNumber, $request) {
            $subtotal = '0'; $tax = '0'; $total = '0';
            $po = PurchaseOrder::create([
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'supplier_id' => $data['supplier_id'],
                'po_number' => $poNumber,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
                'expected_at' => $data['expected_at'] ?? null,
            ]);
            foreach ($data['items'] as $it) {
                $taxRate = (string) ($it['tax_rate'] ?? 0);
                $line = bcmul((string) $it['quantity'], (string) $it['unit_cost'], 4);
                $lineTax = bcmul($line, bcdiv($taxRate, '100', 6), 4);
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $it['product_id'],
                    'product_variant_id' => $it['product_variant_id'] ?? null,
                    'quantity' => $it['quantity'],
                    'unit_cost' => $it['unit_cost'],
                    'tax_rate' => $taxRate,
                    'line_total' => bcadd($line, $lineTax, 4),
                ]);
                $subtotal = bcadd($subtotal, $line, 4);
                $tax = bcadd($tax, $lineTax, 4);
            }
            $po->subtotal = $subtotal;
            $po->tax_amount = $tax;
            $po->total = bcadd($subtotal, $tax, 4);
            $po->save();
            return $po;
        });
        return $this->success($po->load('items'), 'Created', 201);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        if ($purchaseOrder->business_id !== $businessId) return $this->error('Not found', 404);
        return $this->success($purchaseOrder->load('items', 'supplier'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        if ($purchaseOrder->business_id !== $businessId) return $this->error('Not found', 404);
        if ($purchaseOrder->status !== 'draft') return $this->error('Only draft can be edited', 422);
        $this->authz->requirePermission($request->user(), 'purchasing.manage', $businessId);
        $data = $request->validate(['notes' => 'nullable|string', 'status' => 'sometimes|in:draft,cancelled']);
        $purchaseOrder->update($data);
        return $this->success($purchaseOrder);
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        if ($purchaseOrder->business_id !== $businessId) return $this->error('Not found', 404);
        if (! in_array($purchaseOrder->status, ['draft', 'cancelled'])) return $this->error('Cannot delete', 422);
        $purchaseOrder->delete();
        return $this->success(null, 'Deleted');
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        if ($purchaseOrder->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'purchasing.manage', $businessId);
        if ($purchaseOrder->status !== 'draft' && $purchaseOrder->status !== 'submitted') return $this->error('Invalid state', 422);
        $purchaseOrder->status = 'approved';
        $purchaseOrder->approved_by = $request->user()->id;
        $purchaseOrder->approved_at = now();
        $purchaseOrder->save();
        return $this->success($purchaseOrder);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id') ?? $purchaseOrder->outlet_id;
        if ($purchaseOrder->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'purchasing.manage', $businessId);
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'nullable|numeric',
        ]);
        DB::transaction(function () use ($purchaseOrder, $data, $businessId, $outletId, $request) {
            $receipt = GoodsReceipt::create([
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'purchase_order_id' => $purchaseOrder->id,
                'supplier_id' => $purchaseOrder->supplier_id,
                'receipt_number' => 'GR-' . now()->format('Ymd') . '-' . uniqid(),
                'status' => 'completed',
                'received_by' => $request->user()->id,
                'received_at' => now(),
            ]);
            foreach ($data['items'] as $it) {
                $poi = PurchaseOrderItem::findOrFail($it['purchase_order_item_id']);
                $qty = (string) $it['quantity'];
                $unitCost = (string) ($it['unit_cost'] ?? $poi->unit_cost);
                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'product_id' => $poi->product_id,
                    'product_variant_id' => $poi->product_variant_id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                ]);
                // update PO item received
                $poi->received_quantity = bcadd((string) $poi->received_quantity, $qty, 4);
                $poi->save();
                // inventory increment
                $bal = InventoryBalance::lockForUpdate()->firstOrCreate(
                    ['outlet_id' => $outletId, 'product_id' => $poi->product_id, 'product_variant_id' => $poi->product_variant_id],
                    ['business_id' => $businessId, 'quantity' => 0, 'reserved_quantity' => 0]
                );
                $bal->quantity = bcadd((string) $bal->quantity, $qty, 4);
                $bal->save();
                InventoryMovement::create([
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'product_id' => $poi->product_id,
                    'product_variant_id' => $poi->product_variant_id,
                    'type' => 'receipt',
                    'quantity' => $qty,
                    'balance_after' => $bal->quantity,
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receipt->id,
                    'actor_id' => $request->user()->id,
                ]);
            }
            // update PO status
            $totalQty = $purchaseOrder->items->sum('quantity');
            $receivedQty = $purchaseOrder->items->sum('received_quantity');
            if (bccomp((string) $receivedQty, (string) $totalQty, 4) >= 0) {
                $purchaseOrder->status = 'completed';
            } else {
                $purchaseOrder->status = 'partially_received';
            }
            $purchaseOrder->save();
        });
        return $this->success($purchaseOrder->fresh()->load('items'), 'Received');
    }
}
