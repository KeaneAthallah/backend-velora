<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'products.view', $businessId);
        $q = Product::where('business_id', $businessId)->with('category', 'variants');
        if ($request->filled('search')) {
            $s = $request->input('search');
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%");
            });
        }
        if ($request->filled('category_id')) {
            $q->where('category_id', $request->input('category_id'));
        }
        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        return $this->success($q->paginate((int) $request->input('per_page', 50)));
    }

    public function search(Request $request)
    {
        return $this->index($request);
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'products.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:128',
            'category_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'unit' => 'nullable|string|max:32',
            'cost_price' => 'nullable|numeric',
            'selling_price' => 'nullable|numeric',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
            'attributes' => 'nullable|array',
            'variants' => 'nullable|array',
        ]);
        $product = Product::create([
            'business_id' => $businessId,
            'category_id' => $data['category_id'] ?? null,
            'name' => $data['name'],
            'sku' => $data['sku'],
            'description' => $data['description'] ?? null,
            'unit' => $data['unit'] ?? 'pcs',
            'cost_price' => $data['cost_price'] ?? 0,
            'selling_price' => $data['selling_price'] ?? 0,
            'track_inventory' => $data['track_inventory'] ?? true,
            'is_active' => $data['is_active'] ?? true,
            'attributes' => $data['attributes'] ?? null,
        ]);
        if (! empty($data['variants'])) {
            foreach ($data['variants'] as $v) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'name' => $v['name'],
                    'sku' => $v['sku'],
                    'barcode' => $v['barcode'] ?? null,
                    'cost_price' => $v['cost_price'] ?? null,
                    'selling_price' => $v['selling_price'] ?? null,
                    'attributes' => $v['attributes'] ?? null,
                    'is_active' => $v['is_active'] ?? true,
                ]);
            }
        }
        return $this->success($product->load('variants'), 'Created', 201);
    }

    public function show(Request $request, Product $product)
    {
        $businessId = $request->attributes->get('business_id');
        if ($product->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'products.view', $businessId);
        return $this->success($product->load('category', 'variants', 'barcodes', 'outletPrices'));
    }

    public function update(Request $request, Product $product)
    {
        $businessId = $request->attributes->get('business_id');
        if ($product->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'products.manage', $businessId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'sku' => 'sometimes|string|max:128',
            'category_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'unit' => 'nullable|string|max:32',
            'cost_price' => 'nullable|numeric',
            'selling_price' => 'nullable|numeric',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
            'attributes' => 'nullable|array',
        ]);
        $product->update($data);
        return $this->success($product->load('variants'));
    }

    public function destroy(Request $request, Product $product)
    {
        $businessId = $request->attributes->get('business_id');
        if ($product->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'products.manage', $businessId);
        $product->delete();
        return $this->success(null, 'Deleted');
    }
}
