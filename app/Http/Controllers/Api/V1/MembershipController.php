<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessMembership;
use App\Models\Outlet;
use App\Models\OutletMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MembershipController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'employees.view', $businessId);
        $memberships = BusinessMembership::where('business_id', $businessId)
            ->with([
                'user',
                'role',
                'user.outletMemberships' => fn ($query) => $query->whereHas(
                    'outlet',
                    fn ($outlet) => $outlet->where('business_id', $businessId)
                ),
                'user.outletMemberships.outlet',
            ])
            ->paginate(50);

        $memberships->getCollection()->transform(function (BusinessMembership $membership) {
            $membership->setRelation(
                'outlets',
                $membership->user->outletMemberships->pluck('outlet')->filter()->map(
                    fn (Outlet $outlet) => [
                        'id' => $outlet->id,
                        'name' => $outlet->name,
                        'code' => $outlet->code,
                    ]
                )->values()
            );
            $membership->user->unsetRelation('outletMemberships');

            return $membership;
        });

        return $this->success($memberships);
    }

    public function invite(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'employees.manage', $businessId);
        $data = $request->validate([
            'email' => 'required|email',
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'outlet_ids' => 'nullable|array',
            'outlet_ids.*' => [
                'integer',
                Rule::exists('outlets', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
        ]);

        // Re-inviting an existing active member must not consume another seat.
        $existingUser = User::where('email', $data['email'])->first();
        $alreadyMember = $existingUser
            && BusinessMembership::where('user_id', $existingUser->id)
                ->where('business_id', $businessId)
                ->where('status', 'active')
                ->exists();

        if (! $alreadyMember) {
            $this->subscriptions->enforceLimit($businessId, 'users');
        }

        $membership = DB::transaction(function () use ($data, $businessId) {
            $user = User::firstOrCreate(['email' => $data['email']], [
                'name' => explode('@', $data['email'])[0],
                'password' => bcrypt(Str::random(16)),
                'status' => 'active',
            ]);
            $role = Role::where('id', $data['role_id'])->where('business_id', $businessId)->firstOrFail();
            $membership = BusinessMembership::updateOrCreate(
                ['user_id' => $user->id, 'business_id' => $businessId],
                ['role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]
            );

            if (array_key_exists('outlet_ids', $data)) {
                $this->syncOutlets($user, $businessId, $data['outlet_ids'] ?? [], $role->id);
            }

            return $membership;
        });

        return $this->success($this->presentMembership($membership, $businessId), 'Invited', 201);
    }

    public function update(Request $request, BusinessMembership $membership)
    {
        $businessId = $request->attributes->get('business_id');
        if ($membership->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'employees.manage', $businessId);
        $data = $request->validate([
            'role_id' => [
                'nullable',
                Rule::exists('roles', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'status' => 'sometimes|in:active,inactive,invited',
            'outlet_ids' => 'nullable|array',
            'outlet_ids.*' => [
                'integer',
                Rule::exists('outlets', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
        ]);

        DB::transaction(function () use ($membership, $data, $businessId) {
            if (isset($data['role_id'])) {
                $role = Role::where('id', $data['role_id'])->where('business_id', $businessId)->firstOrFail();
                $data['role_id'] = $role->id;
                OutletMembership::where('user_id', $membership->user_id)
                    ->whereHas('outlet', fn ($query) => $query->where('business_id', $businessId))
                    ->update(['role_id' => $role->id]);
            }

            $membership->update(collect($data)->except('outlet_ids')->all());

            if (array_key_exists('outlet_ids', $data)) {
                $this->syncOutlets(
                    $membership->user,
                    $businessId,
                    $data['outlet_ids'] ?? [],
                    $membership->role_id
                );
            }
        });

        return $this->success($this->presentMembership($membership->fresh(), $businessId));
    }

    public function destroy(Request $request, BusinessMembership $membership)
    {
        $businessId = $request->attributes->get('business_id');
        if ($membership->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'employees.manage', $businessId);

        DB::transaction(function () use ($membership, $businessId) {
            $outletIds = Outlet::where('business_id', $businessId)->pluck('id');
            OutletMembership::where('user_id', $membership->user_id)
                ->whereIn('outlet_id', $outletIds)
                ->delete();
            $membership->delete();
        });

        return $this->success(null, 'Removed');
    }

    private function syncOutlets(User $user, int $businessId, array $outletIds, int $roleId): void
    {
        $businessOutletIds = Outlet::where('business_id', $businessId)->pluck('id');
        $selected = collect($outletIds)
            ->map(fn ($id) => (int) $id)
            ->intersect($businessOutletIds)
            ->unique()
            ->values();

        OutletMembership::where('user_id', $user->id)
            ->whereIn('outlet_id', $businessOutletIds)
            ->whereNotIn('outlet_id', $selected)
            ->delete();

        foreach ($selected as $outletId) {
            OutletMembership::updateOrCreate(
                ['user_id' => $user->id, 'outlet_id' => $outletId],
                ['role_id' => $roleId]
            );
        }
    }

    private function presentMembership(BusinessMembership $membership, int $businessId): BusinessMembership
    {
        $membership->load('user', 'role');
        $outletIds = OutletMembership::where('user_id', $membership->user_id)->pluck('outlet_id');
        $membership->setRelation(
            'outlets',
            Outlet::where('business_id', $businessId)
                ->whereIn('id', $outletIds)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
        );

        return $membership;
    }
}
