<?php

namespace App\Jobs;

use App\Models\DailyReport;
use App\Models\Tenants;
use App\Notifications\WeeklyReportNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateWeeklySalesReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ════════════════════════════════════════════════════════════
     * GENERATE WEEKLY SALES REPORT JOB
     * ════════════════════════════════════════════════════════════
     * 
     * គោលបំណង: បង្កើតរបាយការណ៍លក់ប្រចាំសប្តាហ៍
     * 
     * Schedule: ថ្ងៃអាទិត្យ នៅម៉ោង 23:00 (Sunday at 23:00)
     * 
     * Process:
     * ① ទាញយកទិន្នន័យ 7 ថ្ងៃកន្លងទៅ (Monday-Sunday)
     * ② សង្ខេបទិន្នន័យសប្តាហ៍
     * ③ ប្រៀបធៀបជាមួយសប្តាហ៍មុន
     * ④ ផ្ញើជូនអ្នកគ្រប់គ្រង
     */
    public function handle(): void
    {
        Log::info('========================================');
        Log::info('Weekly Sales Report Generation Started');
        Log::info('========================================');

        // Get date range (last 7 days: Monday to Sunday)
        $endDate = now()->endOfWeek(); // Sunday
        $startDate = now()->startOfWeek(); // Monday

        Log::info("Week: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");

        // Get all active tenants
        $tenants = Tenants::where('status', 'active')->get();

        Log::info("Found {$tenants->count()} active tenants");

        foreach ($tenants as $tenant) {
            try {
                Log::info("Processing tenant: {$tenant->company_name}");

                // Generate weekly summary
                $summary = $this->generateWeeklySummary(
                    $tenant->tenant_id,
                    $startDate,
                    $endDate
                );

                // Get comparison with previous week
                $comparison = $this->getWeeklyComparison(
                    $tenant->tenant_id,
                    $startDate,
                    $endDate
                );

                // Notify managers
                $this->notifyManagers($tenant, $summary, $comparison);

                Log::info("Weekly report generated for {$tenant->company_name}");

            } catch (\Exception $e) {
                Log::error("Failed to generate weekly report for {$tenant->company_name}: " . $e->getMessage());
            }
        }

        Log::info('========================================');
        Log::info('Weekly Sales Report Generation Completed');
        Log::info('========================================');
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GENERATE WEEKLY SUMMARY
     * ════════════════════════════════════════════════════════════
     */
    protected function generateWeeklySummary($tenantId, $startDate, $endDate): array
    {
        // Get all daily reports for the week
        $dailyReports = DailyReport::where('tenant_id', $tenantId)
            ->whereNull('branch_id') // Combined reports only
            ->whereBetween('report_date', [$startDate, $endDate])
            ->get();

        // Calculate weekly totals
        $summary = [
            'week_start' => $startDate->format('Y-m-d'),
            'week_end' => $endDate->format('Y-m-d'),
            'total_sales' => $dailyReports->sum('total_sales'),
            'total_transactions' => $dailyReports->sum('transaction_count'),
            'average_daily_sales' => $dailyReports->avg('total_sales'),
            'average_transaction' => $dailyReports->avg('average_transaction'),
            'total_tax' => $dailyReports->sum('total_tax'),
            'total_discount' => $dailyReports->sum('total_discount'),
            'total_customers' => $dailyReports->sum('customer_count'),
            'days_operated' => $dailyReports->count(),
            'best_day' => $dailyReports->sortByDesc('total_sales')->first(),
            'worst_day' => $dailyReports->sortBy('total_sales')->first(),
        ];

        return $summary;
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GET WEEKLY COMPARISON
     * ════════════════════════════════════════════════════════════
     */
    protected function getWeeklyComparison($tenantId, $startDate, $endDate): array
    {
        // Previous week
        $prevStartDate = $startDate->copy()->subWeek();
        $prevEndDate = $endDate->copy()->subWeek();

        $currentWeek = DailyReport::where('tenant_id', $tenantId)
            ->whereNull('branch_id')
            ->whereBetween('report_date', [$startDate, $endDate])
            ->sum('total_sales');

        $previousWeek = DailyReport::where('tenant_id', $tenantId)
            ->whereNull('branch_id')
            ->whereBetween('report_date', [$prevStartDate, $prevEndDate])
            ->sum('total_sales');

        $change = $currentWeek - $previousWeek;
        $percentageChange = $previousWeek > 0 
            ? (($change / $previousWeek) * 100) 
            : 0;

        return [
            'current_week_sales' => $currentWeek,
            'previous_week_sales' => $previousWeek,
            'change_amount' => $change,
            'change_percentage' => round($percentageChange, 2),
            'trend' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * ════════════════════════════════════════════════════════════
     * NOTIFY MANAGERS
     * ════════════════════════════════════════════════════════════
     */
    protected function notifyManagers(Tenants $tenant, array $summary, array $comparison): void
    {
        $managers = \App\Models\User::where('tenant_id', $tenant->tenant_id)
            ->whereIn('role', ['admin', 'manager'])
            ->where('is_active', true)
            ->get();

        foreach ($managers as $manager) {
            try {
                $manager->notify(new WeeklyReportNotification($summary, $comparison));
            } catch (\Exception $e) {
                Log::error("Failed to send weekly report to {$manager->email}: " . $e->getMessage());
            }
        }
    }
}