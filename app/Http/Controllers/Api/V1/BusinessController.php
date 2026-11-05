<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\IndustryChangeBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuthorizationService;
use App\Services\IndustryService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected SubscriptionService $subscriptions,
        protected IndustryService $industries,
    ) {}

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
        $this->industries->ensureSeeded();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:64',
            'currency' => 'nullable|string|size:3',
            'timezone' => 'nullable|string|max:64',
            'settings' => 'nullable|array',
            'industry' => ['nullable', 'string', 'max:32', Rule::exists('industries', 'slug')->where('is_active', true)],
        ]);
        $industry = $data['industry'] ?? $this->industries->defaultIndustry();

        $business = Business::create(array_merge($data, [
            'slug' => Str::slug($data['name']).'-'.uniqid(),
            'status' => 'active',
            'industry' => $industry,
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

        // A new workspace starts on the 30-day trial. Creating it here (rather
        // than only lazily on first tenant request) means the onboarding
        // screen can show the trial dates immediately.
        $this->subscriptions->forBusiness($business, $request->user());

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
        $this->industries->ensureSeeded();

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
            'industry' => ['sometimes', 'string', 'max:32', Rule::exists('industries', 'slug')->where('is_active', true)],
            'force' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('industry', $data) && $data['industry'] !== $business->industry) {
            // authorization for industry change: owner/admin of business or platform admin
            if (! $user->is_platform_admin) {
                $membership = BusinessMembership::where('user_id', $user->id)
                    ->where('business_id', $business->id)
                    ->where('status', 'active')
                    ->first();
                $roleSlug = $membership?->role?->slug;
                if (! in_array($roleSlug, ['owner', 'admin'], true)) {
                    return $this->error('Forbidden', 403, [], 'FORBIDDEN');
                }
            }

            $force = $request->boolean('force') && $user->is_platform_admin;

            try {
                $this->industries->assertChangeAllowed($business, $data['industry'], $force, $user);
            } catch (IndustryChangeBlockedException $e) {
                return $this->error('Industry change is blocked by existing operational data.', 422, $e->counts, 'INDUSTRY_CHANGE_BLOCKED');
            }
        }

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
