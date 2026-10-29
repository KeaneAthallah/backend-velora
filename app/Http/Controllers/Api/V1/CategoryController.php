<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'products.view', $businessId);

        return $this->success(Category::where('business_id', $businessId)->with('children')->paginate(100));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'products.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('business_id', $businessId),
            ],
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);
        $slug = Str::slug($data['name']);
        $cat = Category::create(array_merge($data, ['business_id' => $businessId, 'slug' => $slug]));

        return $this->success($cat, 'Created', 201);
    }

    public function show(Request $request, Category $category)
    {
        $businessId = $request->attributes->get('business_id');
        if ($category->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }

        return $this->success($category);
    }

    public function update(Request $request, Category $category)
    {
        $businessId = $request->attributes->get('business_id');
        if ($category->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'products.manage', $businessId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('business_id', $businessId)
                    ->where('id', '!=', $category->id),
            ],
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);
        $category->update($data);

        return $this->success($category);
    }

    public function destroy(Request $request, Category $category)
    {
        $businessId = $request->attributes->get('business_id');
        if ($category->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'products.manage', $businessId);
        $category->delete();

        return $this->success(null, 'Deleted');
    }
}
