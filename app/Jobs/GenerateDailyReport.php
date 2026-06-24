<?php

namespace App\Jobs;

use App\Models\Tenants;
use App\Services\DailyReportService;
use App\Notifications\DailyReportNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateDailyReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ════════════════════════════════════════════════════════════
     * GENERATE DAILY REPORT JOB
     * ════════════════════════════════════════════════════════════
     * 
     * គោលបំណង: បង្កើតរបាយការណ៍ប្រចាំថ្ងៃដោយស្វ័យប្រវត្តិ
     * 
     * Schedule: ជារៀងរាល់ថ្ងៃ នៅម៉ោង 23:00 (11 PM)
     * 
     * Process:
     * ① ទាញយកក្រុមហ៊ុនទាំងអស់ដែលសកម្ម
     * ② សម្រាប់ក្រុមហ៊ុននីមួយៗ:
     *    - បង្កើតរបាយការណ៍សម្រាប់សាខានីមួយៗ
     *    - បង្កើតរបាយការណ៍រួម
     * ③ ផ្ញើជូនអ្នកគ្រប់គ្រង
     * 
     * Run manually:
     * php artisan queue:work --once
     * dispatch(new GenerateDailyReport());
     */
    public function handle(DailyReportService $reportService): void
    {
        Log::info('========================================');
        Log::info('Daily Report Generation Started');
        Log::info('========================================');

        $startTime = now();

        // ① ទាញយកក្រុមហ៊ុនទាំងអស់ដែលសកម្ម
        $tenants = Tenants::where('status', 'active')->get();

        Log::info("Found {$tenants->count()} active tenants");

        $totalReports = 0;
        $failedTenants = 0;

        // ② Loop តាមក្រុមហ៊ុននីមួយៗ
        foreach ($tenants as $tenant) {
            try {
                Log::info("Processing tenant: {$tenant->company_name}", [
                    'tenant_id' => $tenant->tenant_id,
                ]);

                // បង្កើតរបាយការណ៍សម្រាប់សាខាទាំងអស់
                $reports = $reportService->generateForAllBranches(
                    tenantId: $tenant->tenant_id,
                    date: now()->subDay()->toDateString()
                );

                $totalReports += count($reports);

                Log::info("Generated {count($reports)} reports for {$tenant->company_name}");

                // ③ ផ្ញើជូនអ្នកគ្រប់គ្រង
                $this->notifyManagers($tenant, $reports);

            } catch (\Exception $e) {
                $failedTenants++;
                
                Log::error("Failed to generate reports for tenant: {$tenant->company_name}", [
                    'tenant_id' => $tenant->tenant_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $duration = now()->diffInSeconds($startTime);

        Log::info('========================================');
        Log::info('Daily Report Generation Completed');
        Log::info("Total Reports: {$totalReports}");
        Log::info("Failed Tenants: {$failedTenants}");
        Log::info("Duration: {$duration} seconds");
        Log::info('========================================');
    }

    /**
     * ════════════════════════════════════════════════════════════
     * NOTIFY MANAGERS - ជូនដំណឹងអ្នកគ្រប់គ្រង
     * ════════════════════════════════════════════════════════════
     * 
     * ពន្យល់: ផ្ញើរបាយការណ៍ទៅអ្នកគ្រប់គ្រងនិងAdmin
     */
    protected function notifyManagers(Tenants $tenant, array $reports): void
    {
        // Get managers and admins
        $managers = \App\Models\User::where('tenant_id', $tenant->tenant_id)
            ->whereIn('role', ['admin', 'manager'])
            ->where('is_active', true)
            ->get();

        if ($managers->isEmpty()) {
            Log::warning("No managers found for tenant: {$tenant->company_name}");
            return;
        }

        // Get combined report (branch_id = null)
        $combinedReport = collect($reports)->firstWhere('branch_id', null);

        if (!$combinedReport) {
            Log::warning("No combined report found for tenant: {$tenant->company_name}");
            return;
        }

        // Send notification to each manager
        foreach ($managers as $manager) {
            try {
                $manager->notify(new DailyReportNotification($combinedReport));
                
                Log::info("Notification sent to manager", [
                    'user' => $manager->full_name,
                    'email' => $manager->email,
                ]);

            } catch (\Exception $e) {
                Log::error("Failed to send notification", [
                    'user' => $manager->full_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}