<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\TaxRate;
use App\Services\AuthorizationService;
use App\Services\MenuService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected MenuService $menu,
    ) {}

    /**
     * Everything the register needs to keep selling with no network.
     *
     * The POS stores this snapshot and replays queued sales through the normal
     * sale endpoint, so the payload mirrors what the online screens read: the
     * outlet menu, payment rails, tax rates and the customer book.
     */
    public function bootstrap(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'sales.create', $businessId);

        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        $outlet = $outletId
            ? Outlet::where('business_id', $businessId)->find((int) $outletId)
            : Outlet::where('business_id', $businessId)->orderBy('id')->first();

        if (! $outlet) {
            return $this->error('Not found', 404);
        }

        PaymentMethod::ensureDefaults($businessId);

        $business = Business::find($businessId);
        $limit = min(1000, max(1, (int) $request->input('customers_limit', 500)));

        return $this->success([
            'business' => [
                'id' => $business?->id,
                'name' => $business?->name,
                'currency' => $business?->currency,
                'timezone' => $business?->timezone,
            ],
            'outlet' => [
                'id' => $outlet->id,
                'name' => $outlet->name,
                'code' => $outlet->code,
                'timezone' => $outlet->timezone,
            ],
            'outlets' => Outlet::where('business_id', $businessId)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'timezone']),
            'menu' => $this->menu->menu($businessId, $outlet->id),
            'payment_methods' => PaymentMethod::where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'tax_rates' => TaxRate::where('business_id', $businessId)
                ->where('is_active', true)
                ->get(),
            'customers' => Customer::where('business_id', $businessId)
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get(['id', 'name', 'phone', 'email', 'loyalty_number', 'loyalty_points', 'updated_at']),
            'server_time' => now()->toIso8601String(),
        ], 'Offline snapshot ready');
    }

    public function cursor(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $deviceId = $request->input('device_id');
        $device = Device::where('id', $deviceId)->where('business_id', $businessId)->first();
        if (! $device) {
            return $this->error('Device not found', 404);
        }
        $device->update(['last_seen_at' => now()]);

        return $this->success(['device_id' => $device->id, 'last_seen_at' => $device->last_seen_at]);
    }

    public function pull(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $since = $request->input('since');
        $products = Product::where('business_id', $businessId)->where('updated_at', '>', $since ?? '1970-01-01')->get();

        return $this->success(['products' => $products, 'server_time' => now()->toIso8601String()]);
    }

    public function push(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $data = $request->validate(['operations' => 'required|array']);

        // placeholder: acknowledge
        return $this->success(['received' => count($data['operations']), 'status' => 'accepted']);
    }
}
