<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class SupplierQuotationController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'supplier_quotations.view', $businessId);
        $q = SupplierQuotation::where('business_id', $businessId)->with('supplier', 'product');
        if ($request->filled('product_id')) {
            $q->where('product_id', $request->input('product_id'));
        }

        return $this->success($q->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'supplier_quotations.manage', $businessId);
        $data = $request->validate([
            'supplier_id' => 'required|integer',
            'product_id' => 'required|integer',
            'unit_price' => 'required|integer|min:0',
            'min_quantity' => 'nullable|integer|min:1',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);
        if (! Supplier::where('id', $data['supplier_id'])->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        if (! Product::where('id', $data['product_id'])->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        $q = SupplierQuotation::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($q, 'Quotation created', 201);
    }
}
