<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UsageTrackingService;
use App\Http\Resources\UsageStatsResource;
use Illuminate\Http\Request;

class UsageTrackingController extends Controller
{
    protected $usageService;

    public function __construct(UsageTrackingService $usageService)
    {
        $this->usageService = $usageService;
    }

    /**
     * API 1: GET CURRENT USAGE - ការប្រើប្រាស់បច្ចុប្បន្ន
     * 
     * GET /api/v1/usage/current
     */
    public function current()
    {
        $tenant = app('tenant');

        $usage = $this->usageService->getCurrentUsage($tenant->tenant_id);

        return response()->json([
            'success' => true,
            'data' => new UsageStatsResource($usage),
        ]);
    }

    /**
     * API 2: GET HISTORICAL USAGE - ប្រវត្តិការប្រើប្រាស់
     * 
     * GET /api/v1/usage/history
     */
    public function history(Request $request)
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'metric' => 'nullable|in:transactions,users,branches,terminals',
        ]);

        $history = $this->usageService->getUsageHistory(
            tenantId: $tenant->tenant_id,
            dateFrom: $validated['date_from'] ?? now()->subMonth()->toDateString(),
            dateTo: $validated['date_to'] ?? now()->toDateString(),
            metric: $validated['metric'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * API 3: CHECK SPECIFIC LIMIT - ពិនិត្យកម្រិតជាក់លាក់
     * 
     * GET /api/v1/usage/check/{type}
     * 
     * Types: branches, users, terminals, transactions
     */
    public function checkLimit(string $type)
    {
        $tenant = app('tenant');

        $limitCheck = $this->usageService->checkLimit(
            tenantId: $tenant->tenant_id,
            limitType: $type
        );

        return response()->json([
            'success' => true,
            'data' => $limitCheck,
        ]);
    }

    /**
     * API 4: GET USAGE ALERTS - ការព្រមានការប្រើប្រាស់
     * 
     * GET /api/v1/usage/alerts
     */
    public function alerts()
    {
        $tenant = app('tenant');

        $alerts = $this->usageService->getUsageAlerts($tenant->tenant_id);

        return response()->json([
            'success' => true,
            'data' => $alerts,
        ]);
    }

    /**
     * API 5: GET USAGE FORECAST - ការព្យាករណ៍ការប្រើប្រាស់
     * 
     * GET /api/v1/usage/forecast
     */
    public function forecast()
    {
        $tenant = app('tenant');

        $forecast = $this->usageService->getForecast($tenant->tenant_id);

        return response()->json([
            'success' => true,
            'data' => $forecast,
        ]);
    }
}