<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Sale;
use App\Services\AuthorizationService;
use App\Services\Subscription\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * CSV exports. Standard columns are available on every plan; the `advanced=1`
 * flag is gated by the advanced_export feature and adds the wider column set.
 * Every export consumes one unit of the monthly export quota.
 */
class ExportController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected SubscriptionService $subscriptions,
    ) {}

    public function sales(Request $request)
    {
        $businessId = $this->authorize($request, 'sales.view');
        $advanced = $this->advanced($request, $businessId);
        [$from, $to] = $this->range($request);

        $query = Sale::where('business_id', $businessId)
            ->with(['outlet:id,name', 'cashier:id,name', 'customer:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('outlet_id'), fn ($builder) => $builder->where('outlet_id', $request->input('outlet_id')))
            ->orderBy('id');

        $headers = ['Receipt', 'Order', 'Date', 'Outlet', 'Status', 'Total'];
        if ($advanced) {
            $headers = array_merge($headers, ['Subtotal', 'Discount', 'Tax', 'Paid', 'Change', 'Order type', 'Cashier', 'Customer']);
        }

        $this->consumeExport($businessId);

        return $this->stream('sales', $headers, function ($out) use ($query, $advanced) {
            foreach ($query->cursor() as $sale) {
                $row = [
                    $sale->receipt_number,
                    $sale->order_number,
                    $sale->created_at?->toIso8601String(),
                    $sale->outlet?->name,
                    $sale->status,
                    $sale->total,
                ];
                if ($advanced) {
                    $row = array_merge($row, [
                        $sale->subtotal,
                        $sale->discount_amount,
                        $sale->tax_amount,
                        $sale->paid_amount,
                        $sale->change_amount,
                        $sale->order_type,
                        $sale->cashier?->name,
                        $sale->customer?->name,
                    ]);
                }
                fputcsv($out, $row);
            }
        });
    }

    public function products(Request $request)
    {
        $businessId = $this->authorize($request, 'products.view');
        $advanced = $this->advanced($request, $businessId);

        $query = Product::where('business_id', $businessId)
            ->with('category:id,name')
            ->orderBy('name');

        $headers = ['Name', 'SKU', 'Price', 'Cost', 'Active'];
        if ($advanced) {
            $headers = array_merge($headers, ['Category', 'Unit', 'Tracks inventory', 'Created at']);
        }

        $this->consumeExport($businessId);

        return $this->stream('products', $headers, function ($out) use ($query, $advanced) {
            foreach ($query->cursor() as $product) {
                $row = [
                    $product->name,
                    $product->sku,
                    $product->selling_price,
                    $product->cost_price,
                    $product->is_active ? 'yes' : 'no',
                ];
                if ($advanced) {
                    $row = array_merge($row, [
                        $product->category?->name,
                        $product->unit,
                        $product->track_inventory ? 'yes' : 'no',
                        $product->created_at?->toIso8601String(),
                    ]);
                }
                fputcsv($out, $row);
            }
        });
    }

    public function customers(Request $request)
    {
        $businessId = $this->authorize($request, 'customers.view');
        $advanced = $this->advanced($request, $businessId);

        $query = Customer::where('business_id', $businessId)->orderBy('name');

        $headers = ['Name', 'Phone', 'Email', 'Created at'];
        if ($advanced) {
            $headers = array_merge($headers, ['Address', 'Loyalty number', 'Points', 'Total spent', 'Visits', 'Last visit']);
        }

        $this->consumeExport($businessId);

        return $this->stream('customers', $headers, function ($out) use ($query, $advanced) {
            foreach ($query->cursor() as $customer) {
                $row = [
                    $customer->name,
                    $customer->phone,
                    $customer->email,
                    $customer->created_at?->toIso8601String(),
                ];
                if ($advanced) {
                    $row = array_merge($row, [
                        $customer->address,
                        $customer->loyalty_number,
                        $customer->loyalty_points,
                        $customer->total_spent,
                        $customer->visits_count,
                        $customer->last_visit_at?->toIso8601String(),
                    ]);
                }
                fputcsv($out, $row);
            }
        });
    }

    public function inventory(Request $request)
    {
        $businessId = $this->authorize($request, 'inventory.view');
        $advanced = $this->advanced($request, $businessId);

        $query = InventoryBalance::where('business_id', $businessId)
            ->with(['product:id,name,sku', 'outlet:id,name'])
            ->when($request->filled('outlet_id'), fn ($builder) => $builder->where('outlet_id', $request->input('outlet_id')))
            ->orderBy('id');

        $headers = ['Product', 'SKU', 'Outlet', 'Quantity'];
        if ($advanced) {
            $headers = array_merge($headers, ['Reserved', 'Available', 'Updated at']);
        }

        $this->consumeExport($businessId);

        return $this->stream('inventory', $headers, function ($out) use ($query, $advanced) {
            foreach ($query->cursor() as $balance) {
                $row = [
                    $balance->product?->name,
                    $balance->product?->sku,
                    $balance->outlet?->name,
                    $balance->quantity,
                ];
                if ($advanced) {
                    $row = array_merge($row, [
                        $balance->reserved_quantity,
                        (float) $balance->quantity - (float) $balance->reserved_quantity,
                        $balance->updated_at?->toIso8601String(),
                    ]);
                }
                fputcsv($out, $row);
            }
        });
    }

    /* ------------------------------------------------------------------ */

    private function authorize(Request $request, string $permission): int
    {
        $businessId = (int) $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), $permission, $businessId);

        return $businessId;
    }

    private function advanced(Request $request, int $businessId): bool
    {
        $advanced = $request->boolean('advanced');
        if ($advanced) {
            $this->subscriptions->assertFeature($businessId, 'advanced_export');
        }

        return $advanced;
    }

    private function consumeExport(int $businessId): void
    {
        $this->subscriptions->enforceLimit($businessId, 'exports_per_month');
        $this->subscriptions->recordUsage($businessId, 'exports_per_month');
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

    private function stream(string $name, array $headers, callable $writer)
    {
        $filename = $name.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headers, $writer) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headers);
            $writer($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}
