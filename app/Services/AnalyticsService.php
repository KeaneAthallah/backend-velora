<?php

namespace App\Services;

use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\FinancialTransaction;
use App\Models\InventoryBalance;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReportRun;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SavedReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregation for the Stage 4 analytics surfaces: period comparison, product
 * and service performance, operational health and financial position. Every
 * response keeps the money contract (decimal strings with 4 dp).
 */
class AnalyticsService
{
    /** @return array{0: Carbon, 1: Carbon} */
    public function bounds(?string $from = null, ?string $to = null): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->startOfMonth();
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    /**
     * Compare the selected period with the previous one (or the same period
     * last year).
     */
    public function comparison(int $businessId, Carbon $from, Carbon $to, ?int $outletId = null, string $compare = 'previous_period'): array
    {
        $current = $this->salesMetrics($businessId, $from, $to, $outletId);

        if ($compare === 'previous_year') {
            $previousFrom = $from->copy()->subYearNoOverflow();
            $previousTo = $to->copy()->subYearNoOverflow();
        } else {
            $span = max(1, $from->diffInDays($to) + 1);
            $previousTo = $from->copy()->subSecond();
            $previousFrom = $previousTo->copy()->subDays($span - 1)->startOfDay();
        }

        $previous = $this->salesMetrics($businessId, $previousFrom, $previousTo, $outletId);

        $metrics = [];
        foreach (['revenue', 'orders', 'average_order_value', 'gross_profit', 'discounts', 'tax', 'refunds'] as $key) {
            $a = (float) $current[$key];
            $b = (float) $previous[$key];
            $metrics[$key] = [
                'current' => $this->money($a),
                'previous' => $this->money($b),
                'change_percent' => $b > 0 ? round((($a - $b) / $b) * 100, 2) : null,
            ];
        }
        $metrics['orders']['current'] = (int) $current['orders'];
        $metrics['orders']['previous'] = (int) $previous['orders'];

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'previous_period' => ['from' => $previousFrom->toIso8601String(), 'to' => $previousTo->toIso8601String()],
            'metrics' => $metrics,
        ];
    }

    public function productAnalytics(int $businessId, Carbon $from, Carbon $to, ?int $outletId = null, int $limit = 100): array
    {
        $limit = min(500, max(1, $limit));

        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.business_id', $businessId)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$from, $to])
            ->when($outletId, fn ($query) => $query->where('sales.outlet_id', $outletId))
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'products.sku', 'categories.name')
            ->selectRaw('sale_items.product_id, sale_items.product_name, products.sku, categories.name as category, '
                .'SUM(sale_items.quantity) as quantity, SUM(sale_items.line_total) as revenue, '
                .'SUM(sale_items.cost_price * sale_items.quantity) as cost, '
                .'SUM(sale_items.discount_amount) as discounts, COUNT(DISTINCT sale_items.sale_id) as orders')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $revenueTotal = (float) $rows->sum(fn ($row) => (float) $row->revenue);
        $days = max(1, $from->diffInDays($to) + 1);
        $cumulative = 0.0;

        $products = $rows->map(function ($row) use (&$cumulative, $revenueTotal, $days) {
            $revenue = (float) $row->revenue;
            $cost = (float) $row->cost;
            $margin = $revenue - $cost;
            $share = $revenueTotal > 0 ? ($revenue / $revenueTotal) * 100 : 0;
            // ABC is assigned on the cumulative share *before* this row, so the
            // first product (the top seller) is always class A.
            $cumulativePercent = $revenueTotal > 0 ? ($cumulative / $revenueTotal) * 100 : 0;
            $cumulative += $revenue;

            return [
                'product_id' => $row->product_id ? (string) $row->product_id : null,
                'product_name' => $row->product_name,
                'sku' => $row->sku,
                'category' => $row->category,
                'quantity' => $this->money($row->quantity),
                'orders' => (int) $row->orders,
                'revenue' => $this->money($revenue),
                'cost' => $this->money($cost),
                'discounts' => $this->money($row->discounts),
                'margin' => $this->money($margin),
                'margin_percent' => $revenue > 0 ? round(($margin / $revenue) * 100, 2) : null,
                'revenue_share_percent' => round($share, 2),
                'velocity_per_day' => $this->money(((float) $row->quantity) / $days),
                'abc_class' => $cumulativePercent <= 80 ? 'A' : ($cumulativePercent <= 95 ? 'B' : 'C'),
            ];
        })->values()->all();

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'products' => $products,
            'services' => $this->servicePerformance($businessId, $from, $to, $outletId),
        ];
    }

    public function operational(int $businessId, Carbon $from, Carbon $to, ?int $outletId = null): array
    {
        $base = Sale::where('business_id', $businessId)
            ->whereBetween('created_at', [$from, $to])
            ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId));

        $byStatus = (clone $base)->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $byType = (clone $base)->selectRaw('order_type, COUNT(*) as count')->groupBy('order_type')->pluck('count', 'order_type');

        $byHour = array_fill(0, 24, 0);
        foreach ((clone $base)->pluck('created_at') as $createdAt) {
            $byHour[(int) Carbon::parse($createdAt)->format('G')]++;
        }

        $completed = (clone $base)->where('status', 'completed');
        $prepSeconds = [];
        foreach ((clone $completed)->whereNotNull('ready_at')->get(['created_at', 'ready_at']) as $sale) {
            $prepSeconds[] = max(0, Carbon::parse($sale->ready_at)->diffInSeconds(Carbon::parse($sale->created_at)));
        }

        $revenueRow = (clone $completed)->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')->first();
        $orders = (int) ($revenueRow->orders ?? 0);

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'orders_by_status' => $byStatus,
            'orders_by_type' => $byType,
            'orders_by_hour' => $byHour,
            'open_orders' => (clone $base)->whereIn('order_status', Sale::ACTIVE_ORDER_STATUSES)->count(),
            'completed_orders' => $orders,
            'average_order_value' => $this->money($orders > 0 ? (float) $revenueRow->revenue / $orders : 0),
            'average_prep_minutes' => $prepSeconds === [] ? null : round(array_sum($prepSeconds) / count($prepSeconds) / 60, 2),
            'low_stock_count' => InventoryBalance::where('business_id', $businessId)
                ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))
                ->whereColumn('quantity', '<=', 'reserved_quantity')->count(),
        ];
    }

    public function financial(int $businessId, Carbon $from, Carbon $to, ?int $outletId = null): array
    {
        $sales = $this->salesMetrics($businessId, $from, $to, $outletId);

        // `transaction_date` carries a time component once the `date` cast has
        // written it, so compare calendar dates rather than raw strings — a
        // `whereBetween` with `toDateString()` drops the final day.
        $expenses = (float) FinancialTransaction::where('business_id', $businessId)
            ->where('type', 'expense')
            ->where('status', 'posted')
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString())
            ->sum('amount');

        $otherIncome = (float) FinancialTransaction::where('business_id', $businessId)
            ->where('type', 'income')
            ->where('status', 'posted')
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString())
            ->sum('amount');

        $cashCollected = (float) Payment::where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $arOutstanding = (float) ArInvoice::where('business_id', $businessId)
            ->whereNotIn('status', ['paid', 'voided'])
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) as outstanding')
            ->value('outstanding');

        $apOutstanding = (float) ApInvoice::where('business_id', $businessId)
            ->whereNotIn('status', ['paid', 'voided'])
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) as outstanding')
            ->value('outstanding');

        $grossProfit = (float) $sales['gross_profit'];
        $netProfit = $grossProfit + $otherIncome - $expenses;

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'revenue' => $this->money($sales['revenue']),
            'cogs' => $this->money($sales['cogs']),
            'gross_profit' => $this->money($grossProfit),
            'operating_expenses' => $this->money($expenses),
            'other_income' => $this->money($otherIncome),
            'net_profit' => $this->money($netProfit),
            'tax_collected' => $this->money($sales['tax']),
            'refunds' => $this->money($sales['refunds']),
            'cash_collected' => $this->money($cashCollected),
            'accounts_receivable' => $this->money($arOutstanding),
            'accounts_payable' => $this->money($apOutstanding),
            'gross_margin_percent' => (float) $sales['revenue'] > 0 ? round(($grossProfit / (float) $sales['revenue']) * 100, 2) : null,
        ];
    }

    /**
     * Flatten a report type into exportable headers and rows.
     *
     * @param  array<string, mixed>  $filters
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>, meta: array<string, mixed>}
     */
    public function table(string $type, array $filters): array
    {
        [$from, $to] = $this->bounds($filters['from'] ?? null, $filters['to'] ?? null);
        $businessId = (int) $filters['business_id'];
        $outletId = ! empty($filters['outlet_id']) ? (int) $filters['outlet_id'] : null;

        return match ($type) {
            'comparison' => $this->comparisonTable($businessId, $from, $to, $outletId, $filters['compare'] ?? 'previous_period'),
            'product_analytics' => $this->productTable($businessId, $from, $to, $outletId, (int) ($filters['limit'] ?? 100)),
            'operational' => $this->operationalTable($businessId, $from, $to, $outletId),
            'financial' => $this->financialTable($businessId, $from, $to, $outletId),
            default => ['headers' => [], 'rows' => [], 'meta' => []],
        };
    }

    /**
     * Execute a saved report: builds the table, records the run and schedules
     * the next delivery.
     *
     * @return array{run: ReportRun, table: array<string, mixed>}
     */
    public function runSavedReport(SavedReport $report, ?int $userId = null): array
    {
        $filters = array_merge($report->filters ?? [], [
            'business_id' => $report->business_id,
            'from' => $report->filters['from'] ?? now()->startOfMonth()->toDateString(),
            'to' => $report->filters['to'] ?? now()->toDateString(),
        ]);

        $run = ReportRun::create([
            'business_id' => $report->business_id,
            'saved_report_id' => $report->id,
            'type' => $report->type,
            'format' => $report->format,
            'status' => 'running',
            'filters' => $filters,
            'generated_by' => $userId,
            'started_at' => now(),
            'delivery_status' => $report->schedule === 'none' ? null : 'pending',
        ]);

        try {
            $table = $this->table($report->type, $filters);
            $run->update([
                'status' => 'completed',
                'row_count' => count($table['rows']),
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            throw $e;
        }

        $report->update([
            'last_run_at' => now(),
            'next_run_at' => $this->nextRunAt($report),
        ]);

        return ['run' => $run->fresh(), 'table' => $table];
    }

    public function nextRunAt(SavedReport $report): ?Carbon
    {
        if ($report->schedule === 'none') {
            return null;
        }

        $time = $report->schedule_time ?: '08:00';
        $base = now();

        return match ($report->schedule) {
            'daily' => Carbon::parse($base->toDateString().' '.$time)->addDay(),
            'weekly' => Carbon::parse($base->toDateString().' '.$time)->addWeek(),
            'monthly' => Carbon::parse($base->toDateString().' '.$time)->addMonth(),
            default => null,
        };
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, float|int>
     */
    private function salesMetrics(int $businessId, Carbon $from, Carbon $to, ?int $outletId): array
    {
        $row = Sale::where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(subtotal), 0) as subtotal, '
                .'COALESCE(SUM(discount_amount), 0) as discounts, COALESCE(SUM(tax_amount), 0) as tax, '
                .'COALESCE(SUM(total), 0) as revenue')
            ->first();

        $cogs = (float) SaleItem::whereHas('sale', function ($query) use ($businessId, $from, $to, $outletId) {
            $query->where('business_id', $businessId)
                ->where('status', 'completed')
                ->whereBetween('created_at', [$from, $to])
                ->when($outletId, fn ($inner) => $inner->where('outlet_id', $outletId));
        })->selectRaw('COALESCE(SUM(cost_price * quantity), 0) as cogs')->value('cogs');

        $refunds = (float) Refund::where('business_id', $businessId)
            ->whereIn('status', ['approved', 'completed'])
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $orders = (int) ($row->orders ?? 0);
        $revenue = (float) ($row->revenue ?? 0);

        return [
            'orders' => $orders,
            'subtotal' => (float) ($row->subtotal ?? 0),
            'discounts' => (float) ($row->discounts ?? 0),
            'tax' => (float) ($row->tax ?? 0),
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $revenue - $cogs,
            'average_order_value' => $orders > 0 ? $revenue / $orders : 0.0,
            'refunds' => $refunds,
        ];
    }

    private function servicePerformance(int $businessId, Carbon $from, Carbon $to, ?int $outletId): array
    {
        $rows = DB::table('services')
            ->join('appointments', 'appointments.service_id', '=', 'services.id')
            ->where('appointments.business_id', $businessId)
            ->whereBetween('appointments.starts_at', [$from, $to])
            ->when($outletId, fn ($query) => $query->where('appointments.outlet_id', $outletId))
            ->groupBy('services.id', 'services.name', 'services.price')
            ->selectRaw('services.id as service_id, services.name, services.price, '
                .'COUNT(*) as appointments, SUM(CASE WHEN appointments.status IN (\'completed\', \'confirmed\', \'served\') THEN 1 ELSE 0 END) as completed')
            ->get();

        return collect($rows)->map(fn ($row) => [
            'service_id' => (string) $row->service_id,
            'name' => $row->name,
            'appointments' => (int) $row->appointments,
            'completed' => (int) $row->completed,
            'revenue' => $this->money((float) $row->completed * (float) $row->price),
        ])->values()->all();
    }

    private function comparisonTable(int $businessId, Carbon $from, Carbon $to, ?int $outletId, string $compare): array
    {
        $comparison = $this->comparison($businessId, $from, $to, $outletId, $compare);
        $rows = [];
        foreach ($comparison['metrics'] as $metric => $values) {
            $rows[] = [
                str_replace('_', ' ', ucfirst($metric)),
                $values['current'],
                $values['previous'],
                $values['change_percent'] === null ? null : $values['change_percent'].'%',
            ];
        }

        return [
            'headers' => ['Metric', 'Current', 'Previous', 'Change'],
            'rows' => $rows,
            'meta' => $comparison,
        ];
    }

    private function productTable(int $businessId, Carbon $from, Carbon $to, ?int $outletId, int $limit): array
    {
        $data = $this->productAnalytics($businessId, $from, $to, $outletId, $limit);
        $rows = array_map(fn ($row) => [
            $row['product_name'], $row['sku'], $row['category'], $row['quantity'], $row['revenue'],
            $row['cost'], $row['margin'], $row['margin_percent'], $row['revenue_share_percent'], $row['abc_class'],
        ], $data['products']);

        return [
            'headers' => ['Product', 'SKU', 'Category', 'Quantity', 'Revenue', 'Cost', 'Margin', 'Margin %', 'Revenue share %', 'ABC'],
            'rows' => $rows,
            'meta' => $data,
        ];
    }

    private function operationalTable(int $businessId, Carbon $from, Carbon $to, ?int $outletId): array
    {
        $data = $this->operational($businessId, $from, $to, $outletId);
        $rows = [];
        foreach ($data['orders_by_status'] as $status => $count) {
            $rows[] = ['Status', $status, $count];
        }
        foreach ($data['orders_by_type'] as $type => $count) {
            $rows[] = ['Order type', $type, $count];
        }
        $rows[] = ['Completed orders', 'total', $data['completed_orders']];
        $rows[] = ['Average order value', 'total', $data['average_order_value']];
        $rows[] = ['Average prep minutes', 'total', $data['average_prep_minutes']];
        $rows[] = ['Open orders', 'total', $data['open_orders']];
        $rows[] = ['Low stock items', 'total', $data['low_stock_count']];

        return ['headers' => ['Section', 'Metric', 'Value'], 'rows' => $rows, 'meta' => $data];
    }

    private function financialTable(int $businessId, Carbon $from, Carbon $to, ?int $outletId): array
    {
        $data = $this->financial($businessId, $from, $to, $outletId);
        $rows = [];
        foreach (['revenue', 'cogs', 'gross_profit', 'operating_expenses', 'other_income', 'net_profit', 'tax_collected', 'refunds', 'cash_collected', 'accounts_receivable', 'accounts_payable', 'gross_margin_percent'] as $key) {
            $rows[] = [str_replace('_', ' ', ucfirst($key)), $data[$key]];
        }

        return ['headers' => ['Metric', 'Value'], 'rows' => $rows, 'meta' => $data];
    }

    private function money($value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }
}
