<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Product;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function cursor(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $deviceId = $request->input('device_id');
        $device = Device::where('id', $deviceId)->where('business_id', $businessId)->first();
        if (! $device) return $this->error('Device not found', 404);
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
