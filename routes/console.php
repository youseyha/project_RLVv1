<?php

use App\Jobs\AutoRenewSubscriptions;
use App\Jobs\GenerateDailyReport;
use App\Jobs\GenerateMonthlyInventoryReport;
use App\Jobs\GenerateWeeklySalesReport;
use App\Jobs\ProcessAutoReorder;
use App\Jobs\ProcessSubscriptionDowngrade;
use App\Jobs\ProcessSubscriptionRenewal;
use App\Services\PaymentRetryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run auto-reorder check daily at 8:00 AM
Schedule::job(new ProcessAutoReorder())
    ->dailyAt('08:00')
    ->name('auto-reorder-check')
    ->onOneServer(); // Prevent duplicate runs in multi-server setup

// DAILY REPORT - ជារៀងរាល់ថ្ងៃ នៅម៉ោង 23:00
Schedule::job(new GenerateDailyReport())
    ->dailyAt('23:00')
    ->name('generate-daily-report')
    ->onOneServer(); // Run on one server only (for multiple servers)
    // ->runInBackground();
    // ->withoutOverlapping(10); // Don't run if previous job still running

// OTHER SCHEDULED JOBS  
// Auto-reorder check (existing)
Schedule::job(new ProcessAutoReorder())
    ->dailyAt('08:00')
    ->name('process-auto-reorder')
    ->onOneServer();

// Weekly sales report (Sunday at 23:00)
Schedule::job(new GenerateWeeklySalesReport())
    ->weeklyOn(0, '23:00')
    ->name('generate-weekly-sales-report')
    ->onOneServer();

// Monthly inventory report (1st of month at 08:00)
Schedule::job(new GenerateMonthlyInventoryReport())
    ->monthlyOn(1, '08:00')
    ->name('generate-monthly-inventory-report')
    ->onOneServer();

// AUTO RENEW SUBSCRIPTIONS - ជារៀងរាល់ថ្ងៃ នៅពេលពេញមធ្យមរាត្រី
Schedule::job(new AutoRenewSubscriptions())
    ->dailyAt('00:00')
    ->name('auto-renew-subscriptions')
    ->onOneServer();
    // ->runInBackground()
    // ->withoutOverlapping(30);

// Existing jobs...
Schedule::job(new GenerateDailyReport())
    ->dailyAt('23:00')
    ->name('generate-daily-report')
    ->onOneServer();
// Process subscription downgrade
Schedule::job(new ProcessSubscriptionDowngrade())
    ->hourly()
    ->name('subscription-downgrade');
// Process subscription renewal
Schedule::job(new ProcessSubscriptionRenewal())
    ->dailyAt('00:05')
    ->name('subscription-renewal');

    
// Process pending payment retries every minute
Schedule::call(function () {
    app(PaymentRetryService::class)
        ->processPendingRetries();
})
->everyMinute()
->name('payment-retry-scheduler')
->withoutOverlapping()
->onFailure(function () {
    Log::error('Payment retry scheduler failed');
});
// Command for run schedule 
//php artisan schedule:work (run all schedule)
