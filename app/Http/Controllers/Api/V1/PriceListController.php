<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class PriceListController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'price_lists.view', $businessId);

        return $this->success(PriceList::where('business_id', $businessId)->with('items')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'price_lists.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:32',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);
        $list = PriceList::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($list, 'Price list created', 201);
    }

    public function addItem(Request $request, PriceList $priceList)
    {
        $businessId = $request->attributes->get('business_id');
        if ($priceList->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'price_lists.manage', $businessId);
        $data = $request->validate([
            'product_id' => 'required|integer',
            'product_variant_id' => 'nullable|integer',
            'price' => 'required|integer|min:0',
            'min_quantity' => 'nullable|integer|min:1',
        ]);
        $item = PriceListItem::create(array_merge($data, ['price_list_id' => $priceList->id]));

        return $this->success($item, 'Item added', 201);
    }
}
