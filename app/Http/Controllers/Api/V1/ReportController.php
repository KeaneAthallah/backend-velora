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
        $q = Sale::where('business_id', $businessId)->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to]);
        if ($outletId) $q->where('outlet_id', $outletId);
        $sales = $q->get();
        return $this->success([
            'count' => $sales->count(),
            'gross_sales' => $sales->sum('subtotal'),
            'discounts' => $sales->sum('discount_amount'),
            'tax' => $sales->sum('tax_amount'),
            'net_sales' => $sales->sum('total'),
            'paid' => $sales->sum('paid_amount'),
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
            if ($outletId) $q->where('outlet_id', $outletId);
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
        if ($outletId) $q->where('outlet_id', $outletId);
        return $this->success($q->paginate(100));
    }

    public function lowStock(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'inventory.view', $businessId);
        $threshold = (float) $request->input('threshold', 5);
        $outletId = $request->input('outlet_id');
        $q = InventoryBalance::where('business_id', $businessId)->where('quantity', '<=', $threshold)->with('product', 'outlet');
        if ($outletId) $q->where('outlet_id', $outletId);
        $rows = $q->get();
        return $this->success($rows);
    }
}
