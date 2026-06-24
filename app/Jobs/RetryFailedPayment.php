<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RetryFailedPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 1; // Don't retry this job itself
    public $backoff = [60]; // Wait 1 minute before retry

    protected $payment;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    /**
     * ════════════════════════════════════════════════════════════
     * EXECUTE PAYMENT RETRY
     * ════════════════════════════════════════════════════════════
     */
    public function handle(PaymentService $paymentService): void
    {
        try {
            $payment = $this->payment->fresh();

            Log::info('Retrying failed payment', [
                'payment_id' => $payment->payment_id,
                'current_status' => $payment->status,
                'attempt' => $payment->retry_count ?? 0,
            ]);

            // Check if payment has already been resolved
            if (in_array($payment->status, ['completed', 'refunded'])) {
                Log::info('Payment already completed, skipping retry', [
                    'payment_id' => $payment->payment_id,
                ]);
                return;
            }

            // Check if we've exceeded max retries
            $maxRetries = 5;
            $retryCount = $payment->retry_count ?? 0;

            if ($retryCount >= $maxRetries) {
                Log::warning('Max retries exceeded for payment', [
                    'payment_id' => $payment->payment_id,
                    'retry_count' => $retryCount,
                ]);

                // Mark as permanently failed
                $payment->update([
                    'status' => 'failed',
                    'retry_count' => $retryCount,
                    'failed_at' => now(),
                ]);

                // TODO: Send notification to customer
                return;
            }

            // Attempt to verify payment status with gateway
            $statusResult = $paymentService->checkPaymentStatus($payment);

            if (!$statusResult['success']) {
                Log::warning('Failed to check payment status', [
                    'payment_id' => $payment->payment_id,
                    'error' => $statusResult['error'] ?? 'Unknown',
                ]);

                // Schedule next retry
                $this->scheduleNextRetry($payment, $retryCount);
                return;
            }

            // If status is now completed, handle success
            $currentStatus = $statusResult['status'] ?? 'unknown';

            if ($currentStatus === 'completed') {
                Log::info('Payment completed on retry', [
                    'payment_id' => $payment->payment_id,
                ]);

                $paymentService->handleSuccessfulPayment($payment->fresh());
                return;
            }

            // If still pending, schedule next retry
            if ($currentStatus === 'pending') {
                Log::info('Payment still pending, retrying later', [
                    'payment_id' => $payment->payment_id,
                ]);

                $this->scheduleNextRetry($payment, $retryCount);
                return;
            }

            // Payment failed
            Log::warning('Payment failed', [
                'payment_id' => $payment->payment_id,
                'status' => $currentStatus,
            ]);

            $payment->update([
                'status' => 'failed',
                'retry_count' => $retryCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrying payment', [
                'payment_id' => $this->payment->payment_id,
                'error' => $e->getMessage(),
            ]);

            // Schedule next retry
            $payment = $this->payment->fresh();
            $this->scheduleNextRetry($payment, $payment->retry_count ?? 0);
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * SCHEDULE NEXT RETRY WITH EXPONENTIAL BACKOFF
     * ════════════════════════════════════════════════════════════
     */
    protected function scheduleNextRetry(Payment $payment, int $currentRetry): void
    {
        // Exponential backoff: 1 min, 5 min, 15 min, 1 hour, 4 hours
        $delays = [1, 5, 15, 60, 240]; // minutes
        $nextDelay = $delays[$currentRetry] ?? 240; // Default 4 hours

        $nextRetryTime = Carbon::now()->addMinutes($nextDelay);

        DB::transaction(function () use ($payment, $currentRetry, $nextRetryTime) {
            $payment->update([
                'retry_count' => $currentRetry + 1,
                'next_retry_at' => $nextRetryTime,
            ]);
        });

        // Dispatch job for next retry
        RetryFailedPayment::dispatch($payment->fresh())
            ->delay($nextRetryTime)
            ->onQueue('payments');

        Log::info('Next payment retry scheduled', [
            'payment_id' => $payment->payment_id,
            'retry_count' => $currentRetry + 1,
            'next_retry_at' => $nextRetryTime,
            'delay_minutes' => $nextDelay,
        ]);
    }
}