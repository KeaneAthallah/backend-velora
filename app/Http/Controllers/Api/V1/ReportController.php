<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function salesSummary(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'reports.view', $businessId);
        $from = $request->input('from', now()->startOfDay()->toDateTimeString());
        $to = $request->input('to', now()->endOfDay()->toDateTimeString());
        $outletId = $request->input('outlet_id');

        // Aggregate in the database instead of loading every sale into PHP:
        // a busy outlet would otherwise materialise thousands of models just
        // to add six numbers, and float sums drift.
        $row = Sale::where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))
            ->selectRaw(
                'COUNT(*) as aggregate_count, '
                .'COALESCE(SUM(subtotal), 0) as gross_sales, '
                .'COALESCE(SUM(discount_amount), 0) as discounts, '
                .'COALESCE(SUM(tax_amount), 0) as tax, '
                .'COALESCE(SUM(total), 0) as net_sales, '
                .'COALESCE(SUM(paid_amount), 0) as paid'
            )
            ->first();

        // The API contract treats money as a 4-decimal string, never a float.
        $money = static fn ($value): string => number_format((float) ($value ?? 0), 4, '.', '');

        return $this->success([
            'count' => (int) ($row->aggregate_count ?? 0),
            'gross_sales' => $money($row->gross_sales ?? 0),
            'discounts' => $money($row->discounts ?? 0),
            'tax' => $money($row->tax ?? 0),
            'net_sales' => $money($row->net_sales ?? 0),
            'paid' => $money($row->paid ?? 0),
        ]);
    }

    public function salesByProduct(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'reports.view', $businessId);
        $from = $request->input('from', now()->startOfDay()->toDateTimeString());
        $to = $request->input('to', now()->endOfDay()->toDateTimeString());
        $outletId = $request->input('outlet_id');
        $rows = SaleItem::whereHas('sale', function ($q) use ($businessId, $from, $to, $outletId) {
            $q->where('business_id', $businessId)->where('status', 'completed')->whereBetween('created_at', [$from, $to]);
            if ($outletId) {
                $q->where('outlet_id', $outletId);
            }
        })->selectRaw('product_id, product_name, SUM(quantity) as qty, SUM(line_total) as total')
            ->groupBy('product_id', 'product_name')
            ->get();

        return $this->success($rows);
    }

    public function inventoryBalances(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'inventory.view', $businessId);
        $outletId = $request->input('outlet_id');
        $q = InventoryBalance::where('business_id', $businessId)->with('product', 'outlet');
        if ($outletId) {
            $q->where('outlet_id', $outletId);
        }

        return $this->success($q->paginate(100));
    }

    public function lowStock(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'inventory.view', $businessId);
        $threshold = (float) $request->input('threshold', 5);
        $outletId = $request->input('outlet_id');
        $q = InventoryBalance::where('business_id', $businessId)->where('quantity', '<=', $threshold)->with('product', 'outlet');
        if ($outletId) {
            $q->where('outlet_id', $outletId);
        }
        $rows = $q->get();

        return $this->success($rows);
    }
}
