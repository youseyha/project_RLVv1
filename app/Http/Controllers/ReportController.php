<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateReportRequest;
use App\Http\Resources\DailyReportResource;
use App\Http\Resources\DailyReportCollection;
use App\Models\DailyReport;
use App\Services\DailyReportService;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    protected $reportService;
    protected $exportService;

    public function __construct(
        DailyReportService $reportService,
        ReportExportService $exportService
    ) {
        $this->reportService = $reportService;
        $this->exportService = $exportService;
    }

    /**
     * API 1: LIST DAILY REPORTS - បញ្ជីរបាយការណ៍ប្រចាំថ្ងៃ
     * 
     * GET /api/v1/reports/daily
     * 
     * Query Parameters:
     * - branch_id: Filter by branch (optional)
     * - date_from: Start date
     * - date_to: End date
     * - per_page: Items per page (default: 20)
     * 
     * Example:
     * GET /reports/daily?date_from=2026-05-01&date_to=2026-05-07&branch_id=uuid
     */
    public function indexDaily(Request $request)
    {
        $tenant = app('tenant');

        // Build query តាម ERD: DAILY_REPORTS table
        $query = DailyReport::with(['branch'])->where('tenant_id', $tenant->tenant_id);

        // Filter by branch
        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filter by date range
        if ($request->date_from) {
            $query->where('report_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->where('report_date', '<=', $request->date_to);
        }

        // Order and paginate
        $reports = $query->orderBy('report_date', 'desc')
            ->paginate($request->per_page ?? 20);

        return new DailyReportCollection($reports);
    }

    /**
     * API 2: GET SINGLE DAILY REPORT - របាយការណ៍ប្រចាំថ្ងៃមួយ
     * 
     * GET /api/v1/reports/daily/{id}
     */
    public function showDaily(string $id)
    {
        $tenant = app('tenant');

        $report = DailyReport::where('tenant_id', $tenant->tenant_id)
            ->with(['branch'])
            ->findOrFail($id);

        return new DailyReportResource($report);
    }

    /**
     * API 3: GENERATE DAILY REPORT - បង្កើតរបាយការណ៍ប្រចាំថ្ងៃ
     * 
     * POST /api/v1/reports/daily/generate
     * 
     * Body:
     * {
     *   "branch_id": "uuid" (optional - null for all branches),
     *   "date": "2026-05-07" (optional - default yesterday)
     * }
     */
    public function generateDaily(Request $request)
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'branch_id' => 'required|uuid|exists:branches,branch_id',
            'date' => 'nullable|date|before_or_equal:today',
        ]);

        try {
            $report = $this->reportService->generateDailyReport(
                tenantId: $tenant->tenant_id,
                branchId: $validated['branch_id'],
                date: $validated['date'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'របាយការណ៍ត្រូវបានបង្កើតបានជោគជ័យ',
                'data' => new DailyReportResource($report),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 4: GENERATE ALL BRANCHES - បង្កើតសម្រាប់សាខាទាំងអស់
     * 
     * POST /api/v1/reports/daily/generate-all
     * 
     * Body:
     * {
     *   "date": "2026-05-07" (optional)
     * }
     */
    public function generateAllBranches(Request $request)
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'date' => 'nullable|date|before_or_equal:today',
        ]);

        try {
            $reports = $this->reportService->generateForAllBranches(
                tenantId: $tenant->tenant_id,
                date: $validated['date'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => "បានបង្កើតរបាយការណ៍ " . count($tenant->branches) . " សាខា",
                'data' => [
                    'reports' => DailyReportResource::collection($reports),
                    'total_count' => count($reports),
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 5: EXPORT DAILY REPORT - នាំចេញរបាយការណ៍
     * 
     * POST /api/v1/reports/daily/{id}/export
     * 
     * Body:
     * {
     *   "format": "pdf" | "xlsx" | "csv"
     * }
     */
    public function exportDaily(string $id, Request $request)
    {
        $tenant = app('tenant');

        $report = DailyReport::with(['branch'])->where('tenant_id', $tenant->tenant_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'format' => 'required|in:pdf,xlsx,csv',
        ]);

        try {
            $filePath = $this->exportService->exportDailyReport(
                report: $report,
                format: $validated['format']
            );

            return response()->json([
                'success' => true,
                'message' => 'នាំចេញបានជោគជ័យ',
                'data' => [
                    'file_path' => $filePath,
                    'download_url' => Storage::url($filePath),
                    'format' => $validated['format'],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 6: DOWNLOAD REPORT - ទាញយករបាយការណ៍
     * 
     * GET /api/v1/reports/daily/{id}/download?format=pdf
     */
    public function downloadDaily(string $id, Request $request)
    {
        $tenant = app('tenant');

        $report = DailyReport::with(['branch'])->where('tenant_id', $tenant->tenant_id)
            ->findOrFail($id);

        $format = $request->get('format', 'pdf');

        try {
            $filePath = $this->exportService->exportDailyReport(
                report: $report,
                format: $format
            );

            return response()->download(
                storage_path('app/public/' . $filePath)
            );

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 7: SUMMARY STATISTICS - សង្ខេបស្ថិតិ
     * 
     * GET /api/v1/reports/summary
     * 
     * Query Parameters:
     * - branch_id: Filter by branch
     * - date_from: Start date
     * - date_to: End date
     */
    public function summary(Request $request)
    {
        $tenant = app('tenant');

        try {
            $summary = $this->reportService->getReportSummary(
                tenantId: $tenant->tenant_id,
                branchId: $request->branch_id,
                dateFrom: $request->date_from,
                dateTo: $request->date_to
            );

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 8: COMPARISON REPORT - ប្រៀបធៀបរយៈពេល
     * 
     * GET /api/v1/reports/compare
     * 
     * Compare two time periods
     */
    public function compare(Request $request)
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'period1_from' => 'required|date',
            'period1_to' => 'required|date|after_or_equal:period1_from',
            'period2_from' => 'required|date',
            'period2_to' => 'required|date|after_or_equal:period2_from',
            'branch_id' => 'nullable|uuid|exists:branches,branch_id',
        ]);

        try {
            // Period 1
            $period1 = $this->reportService->getReportSummary(
                tenantId: $tenant->tenant_id,
                branchId: $validated['branch_id'] ?? null,
                dateFrom: $validated['period1_from'],
                dateTo: $validated['period1_to']
            );

            // Period 2
            $period2 = $this->reportService->getReportSummary(
                tenantId: $tenant->tenant_id,
                branchId: $validated['branch_id'] ?? null,
                dateFrom: $validated['period2_from'],
                dateTo: $validated['period2_to']
            );

            // Calculate differences
            $comparison = [
                'period1' => $period1,
                'period2' => $period2,
                'difference' => [
                    'total_sales' => $period1['total_sales'] - $period2['total_sales'],
                    'total_transactions' => $period1['total_transactions'] - $period2['total_transactions'],
                    'average_transaction' => $period1['average_transaction'] - $period2['average_transaction'],
                ],
                //(Current - Previous) / Previous × 100
                'percentage_change' => [
                    'sales' => $period2['total_sales'] > 0 
                        ? (($period1['total_sales'] - $period2['total_sales']) / $period2['total_sales']) * 100 
                        : 0,
                    'transactions' => $period2['total_transactions'] > 0 
                        ? (($period1['total_transactions'] - $period2['total_transactions']) / $period2['total_transactions']) * 100 
                        : 0,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $comparison,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 9: DELETE REPORT - លុបរបាយការណ៍
     * 
     * DELETE /api/v1/reports/daily/{id}
     */
    public function destroyDaily(string $id)
    {
        $tenant = app('tenant');

        $report = DailyReport::where('tenant_id', $tenant->tenant_id)
            ->findOrFail($id);

        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'លុបរបាយការណ៍បានជោគជ័យ',
            'report' => new DailyReportResource($report),
        ]);
    }
}