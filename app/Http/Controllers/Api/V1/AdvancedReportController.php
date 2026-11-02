<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\AuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Pro-only reporting surfaces. Each route is additionally guarded by the
 * `subscription.feature:*` middleware, so this controller can stay focused on
 * aggregation rather than entitlement checks.
 */
class AdvancedReportController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function salesTrend(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'reports.view', $businessId);
        [$from, $to] = $this->range($request);

        $rows = Sale::where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('outlet_id'), fn ($query) => $query->where('outlet_id', $request->input('outlet_id')))
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->day,
                'orders' => (int) $row->orders,
                'revenue' => $this->money($row->revenue),
            ]);

        return $this->success([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'rows' => $rows,
        ]);
    }

    public function outletComparison(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'reports.view', $businessId);
        [$from, $to] = $this->range($request);

        $rows = Sale::query()
            ->join('outlets', 'outlets.id', '=', 'sales.outlet_id')
            ->where('sales.business_id', $businessId)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$from, $to])
            ->selectRaw('outlets.id as outlet_id, outlets.name as outlet_name, COUNT(*) as orders, COALESCE(SUM(sales.total), 0) as revenue')
            ->groupBy('outlets.id', 'outlets.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'outlet_id' => (string) $row->outlet_id,
                'outlet_name' => $row->outlet_name,
                'orders' => (int) $row->orders,
                'revenue' => $this->money($row->revenue),
            ]);

        return $this->success([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'rows' => $rows,
        ]);
    }

    public function productPerformance(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'reports.view', $businessId);
        [$from, $to] = $this->range($request);
        $limit = min(200, max(1, (int) $request->input('limit', 25)));

        $rows = SaleItem::whereHas('sale', function ($query) use ($businessId, $from, $to, $request) {
            $query->where('business_id', $businessId)
                ->where('status', 'completed')
                ->whereBetween('created_at', [$from, $to]);
            if ($request->filled('outlet_id')) {
                $query->where('outlet_id', $request->input('outlet_id'));
            }
        })
            ->selectRaw('product_id, product_name, SUM(quantity) as quantity, SUM(line_total) as revenue, SUM(line_total - (cost_price * quantity)) as margin')
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id ? (string) $row->product_id : null,
                'product_name' => $row->product_name,
                'quantity' => $this->money($row->quantity),
                'revenue' => $this->money($row->revenue),
                'margin' => $this->money($row->margin),
            ]);

        return $this->success([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'rows' => $rows,
        ]);
    }

    public function insights(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'reports.view', $businessId);
        [$from, $to] = $this->range($request);

        $current = $this->aggregate($businessId, $from, $to, $request->input('outlet_id'));
        $span = max(1, $from->diffInDays($to) + 1);
        $previousTo = $from->copy()->subSecond();
        $previousFrom = $previousTo->copy()->subDays($span - 1);
        $previous = $this->aggregate($businessId, $previousFrom, $previousTo, $request->input('outlet_id'));

        $growth = $previous['revenue'] > 0
            ? (($current['revenue'] - $previous['revenue']) / $previous['revenue']) * 100
            : null;

        $bestDay = Sale::where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('outlet_id'), fn ($query) => $query->where('outlet_id', $request->input('outlet_id')))
            ->selectRaw('DATE(created_at) as day, COALESCE(SUM(total), 0) as revenue')
            ->groupBy('day')
            ->orderByDesc('revenue')
            ->first();

        $topOutlet = Sale::query()
            ->join('outlets', 'outlets.id', '=', 'sales.outlet_id')
            ->where('sales.business_id', $businessId)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$from, $to])
            ->selectRaw('outlets.id as outlet_id, outlets.name as outlet_name, COALESCE(SUM(sales.total), 0) as revenue')
            ->groupBy('outlets.id', 'outlets.name')
            ->orderByDesc('revenue')
            ->first();

        return $this->success([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'current' => [
                'orders' => $current['orders'],
                'revenue' => $this->money($current['revenue']),
                'average_order_value' => $this->money($current['average_order_value']),
            ],
            'previous' => [
                'orders' => $previous['orders'],
                'revenue' => $this->money($previous['revenue']),
                'average_order_value' => $this->money($previous['average_order_value']),
            ],
            'growth_percent' => $growth === null ? null : round($growth, 2),
            'best_day' => $bestDay ? [
                'date' => (string) $bestDay->day,
                'revenue' => $this->money($bestDay->revenue),
            ] : null,
            'top_outlet' => $topOutlet ? [
                'outlet_id' => (string) $topOutlet->outlet_id,
                'outlet_name' => $topOutlet->outlet_name,
                'revenue' => $this->money($topOutlet->revenue),
            ] : null,
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function aggregate(int $businessId, Carbon $from, Carbon $to, ?string $outletId): array
    {
        $row = Sale::where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')
            ->first();

        $orders = (int) ($row->orders ?? 0);
        $revenue = (float) ($row->revenue ?? 0);

        return [
            'orders' => $orders,
            'revenue' => $revenue,
            'average_order_value' => $orders > 0 ? $revenue / $orders : 0,
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : now()->startOfMonth();
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        if ($from->greaterThan($to)) {
            $swap = $from;
            $from = $to->copy()->startOfDay();
            $to = $swap->copy()->endOfDay();
        }

        return [$from, $to];
    }

    private function businessId(Request $request): int
    {
        return (int) $request->attributes->get('business_id');
    }

    private function money($value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }
}
