<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerActivityLog;
use App\Models\CustomerGroup;
use App\Models\CustomerNote;
use App\Models\CustomerTag;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class CustomerGroupController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function groups(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'crm.view', $businessId);

        return $this->success(CustomerGroup::where('business_id', $businessId)->get());
    }

    public function storeGroup(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'crm.manage', $businessId);
        $data = $request->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string']);
        $g = CustomerGroup::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($g, 'Group created', 201);
    }

    public function tags(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'crm.view', $businessId);

        return $this->success(CustomerTag::where('business_id', $businessId)->get());
    }

    public function storeTag(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'crm.manage', $businessId);
        $data = $request->validate(['name' => 'required|string|max:255', 'color' => 'nullable|string|max:16']);
        $t = CustomerTag::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($t, 'Tag created', 201);
    }

    public function assignGroups(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'crm.manage', $businessId);
        $data = $request->validate(['group_ids' => 'required|array', 'group_ids.*' => 'integer']);
        $customer->groups()->sync($data['group_ids']);

        return $this->success($customer->load('groups'));
    }

    public function assignTags(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'crm.manage', $businessId);
        $data = $request->validate(['tag_ids' => 'required|array', 'tag_ids.*' => 'integer']);
        $customer->tags()->sync($data['tag_ids']);

        return $this->success($customer->load('tags'));
    }

    public function notes(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'crm.view', $businessId);

        return $this->success(CustomerNote::where('customer_id', $customer->id)->with('author')->orderByDesc('id')->get());
    }

    public function storeNote(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'crm.manage', $businessId);
        $data = $request->validate(['body' => 'required|string']);
        $note = CustomerNote::create(['business_id' => $businessId, 'customer_id' => $customer->id, 'author_id' => $request->user()->id, 'body' => $data['body']]);

        return $this->success($note, 'Note added', 201);
    }

    public function activity(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'crm.view', $businessId);

        return $this->success(CustomerActivityLog::where('customer_id', $customer->id)->orderByDesc('id')->get());
    }
}
