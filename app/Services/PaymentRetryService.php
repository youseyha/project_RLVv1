<?php

namespace App\Services;

use App\Models\Payment;
use App\Jobs\RetryFailedPayment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentRetryService
{
    /**
     * ════════════════════════════════════════════════════════════
     * SCHEDULE PAYMENT FOR RETRY
     * Called when payment fails initially
     * ════════════════════════════════════════════════════════════
     */
    public function scheduleRetry(Payment $payment): void
    {
        // Schedule first retry after 1 minute
        $firstRetryTime = Carbon::now()->addMinute();

        DB::transaction(function () use ($payment, $firstRetryTime) {
            $payment->update([
                'retry_count' => 0,
                'next_retry_at' => $firstRetryTime,
            ]);
        });

        RetryFailedPayment::dispatch($payment)
            ->delay($firstRetryTime)
            ->onQueue('payments');

        Log::info('Payment scheduled for retry', [
            'payment_id' => $payment->payment_id,
            'next_retry_at' => $firstRetryTime,
        ]);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PROCESS PENDING RETRIES
     * Run by scheduler to process failed payments
     * ════════════════════════════════════════════════════════════
     */
    public function processPendingRetries(): void
    {
        $pendingRetries = Payment::where('status', 'failed')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->where('retry_count', '<', 5)
            ->get();

        Log::info('Found pending payment retries', [
            'count' => $pendingRetries->count(),
        ]);

        foreach ($pendingRetries as $payment) {
            RetryFailedPayment::dispatch($payment)
                ->onQueue('payments');

            Log::info('Dispatched payment retry job', [
                'payment_id' => $payment->payment_id,
                'retry_count' => $payment->retry_count,
            ]);
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * MANUAL RETRY
     * Admin manually retries a failed payment
     * ════════════════════════════════════════════════════════════
     */
    public function manualRetry(Payment $payment): void
    {
        if ($payment->status === 'completed') {
            throw new \Exception('Cannot retry completed payment');
        }

        // Reset retry count for manual retry
        $payment->update([
            'retry_count' => 0,
            'status' => 'failed', // Set back to failed to allow processing
        ]);

        RetryFailedPayment::dispatch($payment)
            ->onQueue('payments');

        Log::info('Manual payment retry initiated', [
            'payment_id' => $payment->payment_id,
        ]);
    }
}