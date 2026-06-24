<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\Transaction;
use App\Models\Tenants;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyReportService
{
    /**
     * ════════════════════════════════════════════════════════════
     * GENERATE DAILY REPORT - បង្កើតរបាយការណ៍ប្រចាំថ្ងៃ
     * ════════════════════════════════════════════════════════════
     * 
     * គោលបំណង: គណនានិងរក្សាទុកទិន្នន័យលក់ប្រចាំថ្ងៃ
     * 
     * Process:
     * ① ទាញទិន្នន័យប្រតិបត្តិការពីតារាង TRANSACTIONS
     * ② គណនា metrics ទាំងអស់
     * ③ រក្សាទុកក្នុងតារាង DAILY_REPORTS
     * 
     * @param string $tenantId
     * @param string|null $branchId
     * @param string|null $date (default: yesterday)
     * @return DailyReport
     */
    public function generateDailyReport(
        string $tenantId, 
        ?string $branchId = null, 
        ?string $date = null
    ): DailyReport {
        // Default to yesterday (since we run this at end of day)
        $reportDate = $date ?? now()->subDay()->toDateString();

        Log::info("Generating daily report", [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'date' => $reportDate,
        ]);

        // Check if report already exists
        if (DailyReport::reportExists($tenantId, $branchId, $reportDate)) {
            Log::warning("Report already exists", [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'date' => $reportDate,
            ]);
            
            return DailyReport::where('tenant_id', $tenantId)
                              ->where('branch_id', $branchId)
                              ->where('report_date', $reportDate)
                              ->first();
        }

        // ទាញទិន្នន័យប្រតិបត្តិការ 
        // Sale transactions (status = completed) + Refund transactions (status = refunded)
        $salesQuery  = Transaction::whereHas('branch', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->whereDate('transaction_date', $reportDate)
            ->where('status', 'completed'); // Only completed transactions

        // Refund transactions (status = refunded)
        $refundQuery = Transaction::whereHas('branch', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->whereDate('transaction_date', $reportDate)
            ->where('status', 'refunded');
        // Filter by branch if specified
        if ($branchId) {
            $salesQuery->where('branch_id', $branchId);
            $refundQuery->where('branch_id', $branchId);
        }

        $salesTransactions = $salesQuery->get();
        $refundTransactions = $refundQuery->get();

        // គណនា Metrics
        $metrics = $this->calculateMetrics($salesTransactions, $refundTransactions);

        // រក្សាទុកក្នុង DAILY_REPORTS
        $report = DailyReport::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'report_date' => $reportDate,
            'total_sales' => $metrics['total_sales'],
            'transaction_count' => $metrics['transaction_count'],
            'average_transaction' => $metrics['average_transaction'],
            'total_tax' => $metrics['total_tax'],
            'total_discount' => $metrics['total_discount'],
            'customer_count' => $metrics['customer_count'],
            'generated_at' => now(),
        ]);

        Log::info("Daily report generated successfully", [
            'report_id' => $report->report_id,
            'total_sales' => $report->total_sales,
        ]);

        return $report;
    }

    /**
     * CALCULATE METRICS - គណនា Metrics
     * 
     * ពន្យល់: គណនាទិន្នន័យទាំងអស់តាម ERD fields
     */
    protected function calculateMetrics(Collection $salesTransactions, Collection $refundTransactions): array
    {
        $transactionCount = $salesTransactions->count();

        // Fields តាម ERD
        $totalSales = $salesTransactions->sum('total_amount');
        $totalTax = $salesTransactions->sum('tax_amount');
        $totalDiscount = $salesTransactions->sum('discount_amount');
        $totalRefunds = $refundTransactions->sum('total_amount');
        $totalRefundsTax = $refundTransactions->sum('tax_amount');
        $totalRefundsDiscount = $refundTransactions->sum('discount_amount');

        // Net sales after refunds
        $totalSales -= $totalRefunds;

        // Net tax after refunds
        $totalTax -= $totalRefundsTax;

        // Net discount after refunds
        $totalDiscount -= $totalRefundsDiscount;

        // Average transaction
        $averageTransaction = $transactionCount > 0 
            ? $totalSales / $transactionCount 
            : 0;

        // Customer count (unique customers - simplified)
        // Note: ERD doesn't have customer_id in TRANSACTIONS, so we count transactions
        // In real implementation, you might have a customer_id field
        $customerCount = $transactionCount; // Assuming 1 transaction = 1 customer

        return [
            'total_sales' => round($totalSales, 2),
            'transaction_count' => $transactionCount,
            'average_transaction' => round($averageTransaction, 2),
            'total_tax' => round($totalTax, 2),
            'total_discount' => round($totalDiscount, 2),
            'customer_count' => $customerCount,
        ];
    }

    /**
     * GENERATE FOR ALL BRANCHES - បង្កើតសម្រាប់សាខាទាំងអស់
     * 
     * ពន្យល់: បង្កើតរបាយការណ៍សម្រាប់សាខានីមួយៗ + របាយការណ៍រួម
     */
    public function generateForAllBranches(string $tenantId, ?string $date = null): array
    {
        $reportDate = $date ?? now()->subDay()->toDateString();
        $reports = [];

        // Get tenant with branches
        $tenant = Tenants::with('branches')->findOrFail($tenantId);

        // Generate report for each branch
        foreach ($tenant->branches as $branch) {
            try {
                $report = $this->generateDailyReport(
                    tenantId: $tenantId,
                    branchId: $branch->branch_id,
                    date: $reportDate
                );
                
                $reports[] = $report;

                Log::info("Branch report generated", [
                    'branch_id' => $branch->branch_id,
                    'branch_name' => $branch->branch_name,
                ]);

            } catch (\Exception $e) {
                Log::error("Failed to generate branch report", [
                    'branch_id' => $branch->branch_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Generate combined report (all branches)
        try {
            $combinedReport = $this->generateDailyReport(
                tenantId: $tenantId,
                branchId: null, // NULL = all branches
                date: $reportDate
            );
            
            $reports[] = $combinedReport;

            Log::info("Combined report generated", [
                'total_sales' => $combinedReport->total_sales,
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to generate combined report", [
                'error' => $e->getMessage(),
            ]);
        }

        return $reports;
    }

    /**
     * GET REPORT SUMMARY - ទទួលយកសង្ខេប
     */
    public function getReportSummary(
        string $tenantId, 
        ?string $branchId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $query = DailyReport::where('tenant_id', $tenantId);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }else {
            $query->whereNull('branch_id');
        }

        if ($dateFrom) {
            $query->where('report_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('report_date', '<=', $dateTo);
        }

        $reports = $query->get();

        return [
            'total_sales' => $reports->sum('total_sales'),
            'total_transactions' => $reports->sum('transaction_count'),
            'average_transaction' => round($reports->avg('average_transaction'), 3),
            'total_tax' => $reports->sum('total_tax'),
            'total_discount' => $reports->sum('total_discount'),
            'total_customers' => $reports->sum('customer_count'),
            'report_count' => $reports->count(),
        ];
    }
}