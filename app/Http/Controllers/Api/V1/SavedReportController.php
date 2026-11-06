<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedReport;
use App\Services\AnalyticsService;
use App\Services\AuthorizationService;
use App\Services\ReportExportService;
use Illuminate\Http\Request;

class SavedReportController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected AnalyticsService $analytics,
        protected ReportExportService $exports,
    ) {}

    public function index(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'analytics.view', $businessId);

        return $this->success(
            SavedReport::where('business_id', $businessId)
                ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')))
                ->with('creator:id,name')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'analytics.manage', $businessId);
        $data = $this->validated($request);

        $report = SavedReport::create(array_merge($data, [
            'business_id' => $businessId,
            'format' => $data['format'] ?? 'csv',
            'schedule' => $data['schedule'] ?? 'none',
            'is_shared' => $data['is_shared'] ?? false,
            'created_by' => $request->user()->id,
        ]));
        $report->update(['next_run_at' => $this->analytics->nextRunAt($report)]);

        return $this->success($report, 'Saved report created', 201);
    }

    public function show(Request $request, SavedReport $savedReport)
    {
        $this->guard($request, $savedReport);
        $this->authz->requirePermission($request->user(), 'analytics.view', $savedReport->business_id);

        return $this->success($savedReport->load('runs', 'creator:id,name'));
    }

    public function update(Request $request, SavedReport $savedReport)
    {
        $this->guard($request, $savedReport);
        $this->authz->requirePermission($request->user(), 'analytics.manage', $savedReport->business_id);
        $savedReport->update($this->validated($request, true));
        $savedReport->update(['next_run_at' => $this->analytics->nextRunAt($savedReport)]);

        return $this->success($savedReport->fresh());
    }

    public function destroy(Request $request, SavedReport $savedReport)
    {
        $this->guard($request, $savedReport);
        $this->authz->requirePermission($request->user(), 'analytics.manage', $savedReport->business_id);
        $savedReport->delete();

        return $this->success(null, 'Saved report deleted');
    }

    public function run(Request $request, SavedReport $savedReport)
    {
        $this->guard($request, $savedReport);
        $this->authz->requirePermission($request->user(), 'analytics.manage', $savedReport->business_id);

        $result = $this->analytics->runSavedReport($savedReport, $request->user()->id);

        return $this->success([
            'run' => $result['run'],
            'report' => $result['table']['meta'],
            'headers' => $result['table']['headers'],
            'rows' => $result['table']['rows'],
        ], 'Report generated');
    }

    public function runs(Request $request, SavedReport $savedReport)
    {
        $this->guard($request, $savedReport);
        $this->authz->requirePermission($request->user(), 'analytics.view', $savedReport->business_id);

        return $this->success($savedReport->runs()->orderByDesc('id')->paginate(25));
    }

    public function download(Request $request, SavedReport $savedReport)
    {
        $this->guard($request, $savedReport);
        $this->authz->requirePermission($request->user(), 'analytics.view', $savedReport->business_id);

        $filters = array_merge($savedReport->filters ?? [], ['business_id' => $savedReport->business_id]);
        if ($request->filled('from')) {
            $filters['from'] = $request->input('from');
        }
        if ($request->filled('to')) {
            $filters['to'] = $request->input('to');
        }
        if ($request->filled('outlet_id')) {
            $filters['outlet_id'] = $request->input('outlet_id');
        }

        $table = $this->analytics->table($savedReport->type, $filters);
        $format = in_array($request->input('format'), ReportExportService::FORMATS, true)
            ? $request->input('format')
            : $savedReport->format;

        return $this->exports->download($savedReport->name, $table['headers'], $table['rows'], $format);
    }

    /* ------------------------------------------------------------------ */

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => $required.'|string|max:120',
            'type' => $required.'|in:'.implode(',', SavedReport::TYPES),
            'filters' => 'nullable|array',
            'filters.from' => 'nullable|date',
            'filters.to' => 'nullable|date',
            'filters.outlet_id' => 'nullable|integer',
            'filters.compare' => 'nullable|in:previous_period,previous_year',
            'filters.limit' => 'nullable|integer|min:1|max:500',
            'columns' => 'nullable|array',
            'format' => 'nullable|in:'.implode(',', SavedReport::FORMATS),
            'schedule' => 'nullable|in:'.implode(',', SavedReport::SCHEDULES),
            'schedule_time' => 'nullable|date_format:H:i',
            'recipients' => 'nullable|array',
            'recipients.*' => 'email',
            'is_shared' => 'nullable|boolean',
        ]);
    }

    private function guard(Request $request, SavedReport $report): void
    {
        if ((int) $report->business_id !== $this->businessId($request)) {
            abort(404);
        }
    }

    private function businessId(Request $request): int
    {
        return (int) $request->attributes->get('business_id');
    }
}
