<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BusinessController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->is_platform_admin) {
            return $this->success(Business::paginate(20));
        }
        $ids = $user->businessMemberships()->where('status', 'active')->pluck('business_id');

        return $this->success(Business::whereIn('id', $ids)->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:64',
            'currency' => 'nullable|string|size:3',
            'timezone' => 'nullable|string|max:64',
            'settings' => 'nullable|array',
        ]);
        $business = Business::create(array_merge($data, [
            'slug' => Str::slug($data['name']).'-'.uniqid(),
            'status' => 'active',
        ]));
        // assign creator as owner role (create role if none)
        $role = Role::firstOrCreate(
            ['business_id' => $business->id, 'slug' => 'owner'],
            ['name' => 'Owner', 'is_system' => true]
        );
        $role->permissions()->sync(Permission::pluck('id'));
        BusinessMembership::create([
            'user_id' => $request->user()->id,
            'business_id' => $business->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        PaymentMethod::ensureDefaults($business->id);

        return $this->success($business, 'Business created', 201);
    }

    public function show(Request $request, Business $business)
    {
        $user = $request->user();
        if (! $user->is_platform_admin) {
            $membership = BusinessMembership::where('user_id', $user->id)->where('business_id', $business->id)->where('status', 'active')->first();
            if (! $membership) {
                return $this->error('Not found', 404);
            }
        }
        $this->authz->requirePermission($request->user(), 'businesses.view', $business->id);

        return $this->success($business);
    }

    public function update(Request $request, Business $business)
    {
        $user = $request->user();
        if (! $user->is_platform_admin) {
            $membership = BusinessMembership::where('user_id', $user->id)->where('business_id', $business->id)->where('status', 'active')->first();
            if (! $membership) {
                return $this->error('Not found', 404);
            }
        }
        $this->authz->requirePermission($request->user(), 'businesses.manage', $business->id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:64',
            'currency' => 'nullable|string|size:3',
            'timezone' => 'nullable|string|max:64',
            'status' => 'sometimes|in:active,suspended,archived',
            'settings' => 'nullable|array',
        ]);
        $business->update($data);

        return $this->success($business);
    }

    public function destroy(Request $request, Business $business)
    {
        $user = $request->user();
        if (! $user->is_platform_admin) {
            $membership = BusinessMembership::where('user_id', $user->id)->where('business_id', $business->id)->where('status', 'active')->first();
            if (! $membership) {
                return $this->error('Not found', 404);
            }
        }
        $this->authz->requirePermission($request->user(), 'businesses.manage', $business->id);
        $business->delete();

        return $this->success(null, 'Deleted');
    }
}
