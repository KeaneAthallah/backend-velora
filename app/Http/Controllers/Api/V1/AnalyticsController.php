<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedReport;
use App\Services\AnalyticsService;
use App\Services\AuthorizationService;
use App\Services\ReportExportService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected AnalyticsService $analytics,
        protected ReportExportService $exports,
    ) {}

    public function comparison(Request $request)
    {
        $businessId = $this->authorize($request);
        [$from, $to] = $this->analytics->bounds($request->input('from'), $request->input('to'));

        return $this->success($this->analytics->comparison(
            $businessId,
            $from,
            $to,
            $this->outletId($request),
            $request->input('compare', 'previous_period') === 'previous_year' ? 'previous_year' : 'previous_period',
        ));
    }

    public function products(Request $request)
    {
        $businessId = $this->authorize($request);
        [$from, $to] = $this->analytics->bounds($request->input('from'), $request->input('to'));

        return $this->success($this->analytics->productAnalytics(
            $businessId,
            $from,
            $to,
            $this->outletId($request),
            (int) $request->input('limit', 100),
        ));
    }

    public function operational(Request $request)
    {
        $businessId = $this->authorize($request);
        [$from, $to] = $this->analytics->bounds($request->input('from'), $request->input('to'));

        return $this->success($this->analytics->operational($businessId, $from, $to, $this->outletId($request)));
    }

    public function financial(Request $request)
    {
        $businessId = $this->authorize($request);
        [$from, $to] = $this->analytics->bounds($request->input('from'), $request->input('to'));

        return $this->success($this->analytics->financial($businessId, $from, $to, $this->outletId($request)));
    }

    public function export(Request $request, string $type)
    {
        $businessId = $this->authorize($request);
        if (! in_array($type, SavedReport::TYPES, true)) {
            return $this->error('Unknown report type.', 404);
        }

        $table = $this->analytics->table($type, [
            'business_id' => $businessId,
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'outlet_id' => $this->outletId($request),
            'compare' => $request->input('compare', 'previous_period'),
            'limit' => $request->input('limit', 100),
        ]);

        $format = in_array($request->input('format'), ReportExportService::FORMATS, true)
            ? $request->input('format')
            : 'csv';

        return $this->exports->download('analytics-'.$type, $table['headers'], $table['rows'], $format);
    }

    /* ------------------------------------------------------------------ */

    private function authorize(Request $request): int
    {
        $businessId = (int) $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'analytics.view', $businessId);

        return $businessId;
    }

    private function outletId(Request $request): ?int
    {
        return $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;
    }
}
