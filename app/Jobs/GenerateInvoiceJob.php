<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    protected $subscription;

    public function __construct(Subscription $subscription)
    {
        $this->subscription = $subscription;
    }

    public function handle(InvoiceService $invoiceService): void
    {
        try {
            // បង្កើត invoice
            $invoice = $invoiceService->generateSubscriptionInvoice(
                $this->subscription
            );

            Log::info('Invoice generated successfully', [
                'invoice_id' => $invoice->invoice_id,
                'invoice_number' => $invoice->invoice_number,
                'tenant_id' => $this->subscription->tenant_id,
                'amount' => $invoice->total_amount,
                'status' => $invoice->status,
            ]);

            // Update subscription next billing date
            $this->subscription->update([
                'next_billing_date' => $this->subscription->billing_cycle === 'yearly' ?
                    now()->addYear() : now()->addMonth()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate invoice', [
                'subscription_id' => $this->subscription->subscription_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Invoice generation job failed permanently', [
            'subscription_id' => $this->subscription->subscription_id,
            'error' => $exception->getMessage(),
        ]);
    }
}