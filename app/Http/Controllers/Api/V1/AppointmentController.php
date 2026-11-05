<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\AuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'appointments.view', $businessId);

        $query = Appointment::where('business_id', $businessId);
        if ($request->filled('staff_id')) {
            $query->where('staff_id', $request->input('staff_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->success($query->orderBy('starts_at')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'appointments.create', $businessId);

        $data = $request->validate([
            'outlet_id' => 'nullable|integer',
            'service_id' => 'nullable|integer',
            'staff_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'status' => 'nullable|string|max:24',
            'notes' => 'nullable|string',
        ]);

        $starts = Carbon::parse($data['starts_at']);
        $ends = Carbon::parse($data['ends_at']);

        try {
            $appointment = DB::transaction(function () use ($businessId, $data, $starts, $ends) {
                if (! empty($data['staff_id'])) {
                    $conflict = Appointment::where('business_id', $businessId)
                        ->where('staff_id', $data['staff_id'])
                        ->whereNotIn('status', ['cancelled', 'no_show'])
                        ->where('starts_at', '<', $ends)
                        ->where('ends_at', '>', $starts)
                        ->lockForUpdate()
                        ->exists();
                    if ($conflict) {
                        throw new \RuntimeException('APPOINTMENT_CONFLICT');
                    }
                }

                return Appointment::create(array_merge($data, [
                    'business_id' => $businessId,
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'status' => $data['status'] ?? 'pending',
                ]));
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'APPOINTMENT_CONFLICT') {
                return $this->error('Appointment conflicts with an existing booking.', 422, [], 'APPOINTMENT_CONFLICT');
            }
            throw $e;
        }

        return $this->success($appointment, 'Appointment created', 201);
    }

    public function update(Request $request, Appointment $appointment)
    {
        $businessId = $request->attributes->get('business_id');
        if ($appointment->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'appointments.manage', $businessId);

        $data = $request->validate([
            'status' => 'sometimes|string|max:24',
            'notes' => 'nullable|string',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'sometimes|date',
        ]);
        $appointment->update($data);

        return $this->success($appointment);
    }
}
