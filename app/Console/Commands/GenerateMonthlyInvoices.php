<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Jobs\GenerateInvoiceJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GenerateMonthlyInvoices extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'invoices:generate-monthly 
                            {--date= : Generate invoices for specific date (Y-m-d)}
                            {--tenant= : Generate for specific tenant UUID}
                            {--dry-run : Show what would be generated without creating invoices}';

    /**
     * The console command description.
     */
    protected $description = 'Generate monthly invoices for active subscriptions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('═══════════════════════════════════════════════════');
        $this->info('  GENERATE MONTHLY INVOICES');
        $this->info('═══════════════════════════════════════════════════');

        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $this->info("Date: {$date->format('Y-m-d')}");
        $this->info("Dry Run: " . ($dryRun ? 'Yes' : 'No'));
        $this->newLine();

        // ════════════════════════════════════════════════════════════
        // FIND SUBSCRIPTIONS THAT NEED INVOICES
        // ════════════════════════════════════════════════════════════
        
        $subscriptions = Subscription::with(['tenant', 'plan'])
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->where(function ($query) use ($date) {
                // Next billing date is today or in the past
                $query->whereDate('next_billing_date', '<=', $date->toDateString())
                      // OR next billing date is null and end date is approaching
                      ->orWhere(function ($q) use ($date) {
                          $q->whereNull('next_billing_date')
                            ->whereDate('end_date', '<=', $date->addDays(7)->toDateString());
                      });
            })
            ->when($tenantId, fn($q, $id) => $q->where('tenant_id', $id))
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->warn('No subscriptions found that need invoices.');
            return Command::SUCCESS;
        }

        $this->info("Found {$subscriptions->count()} subscriptions to process:");
        $this->newLine();

        // ════════════════════════════════════════════════════════════
        // CREATE TABLE OF SUBSCRIPTIONS
        // ════════════════════════════════════════════════════════════
        
        $tableData = [];
        foreach ($subscriptions as $subscription) {
            $tableData[] = [
                'Tenant' => $subscription->tenant->company_name,
                'Plan' => $subscription->plan->plan_name,
                'Cycle' => $subscription->billing_cycle,
                'Next Billing' => $subscription->next_billing_date?->format('Y-m-d') ?? 'Not set',
                'Status' => $subscription->status,
            ];
        }

        $this->table(
            ['Tenant', 'Plan', 'Cycle', 'Next Billing', 'Status'],
            $tableData
        );

        $this->newLine();

        // ════════════════════════════════════════════════════════════
        // CONFIRMATION
        // ════════════════════════════════════════════════════════════
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No invoices will be created');
            return Command::SUCCESS;
        }

        if (!$this->confirm('Do you want to proceed with invoice generation?', true)) {
            $this->info('Invoice generation cancelled.');
            return Command::SUCCESS;
        }

        // ════════════════════════════════════════════════════════════
        // PROCESS SUBSCRIPTIONS
        // ════════════════════════════════════════════════════════════
        
        $this->info('Processing subscriptions...');
        $this->newLine();

        $progressBar = $this->output->createProgressBar($subscriptions->count());
        $progressBar->start();

        $successful = 0;
        $failed = 0;
        $errors = [];

        foreach ($subscriptions as $subscription) {
            try {
                // Dispatch job to generate invoice
                GenerateInvoiceJob::dispatch($subscription);

                $successful++;

                Log::info('Invoice generation job dispatched', [
                    'subscription_id' => $subscription->subscription_id,
                    'tenant' => $subscription->tenant->company_name,
                    'plan' => $subscription->plan->plan_name,
                ]);

            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'tenant' => $subscription->tenant->company_name,
                    'error' => $e->getMessage(),
                ];

                Log::error('Failed to dispatch invoice generation job', [
                    'subscription_id' => $subscription->subscription_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // ════════════════════════════════════════════════════════════
        // SUMMARY
        // ════════════════════════════════════════════════════════════
        
        $this->info('═══════════════════════════════════════════════════');
        $this->info('  GENERATION SUMMARY');
        $this->info('═══════════════════════════════════════════════════');
        $this->info("Total Processed: {$subscriptions->count()}");
        $this->info("Successful: {$successful}");
        
        if ($failed > 0) {
            $this->error("Failed: {$failed}");
            $this->newLine();
            $this->error('Errors:');
            $this->table(['Tenant', 'Error'], $errors);
        }

        $this->newLine();
        $this->info('Invoice generation jobs have been queued.');
        $this->info('Check logs for detailed status.');

        return Command::SUCCESS;
    }
}