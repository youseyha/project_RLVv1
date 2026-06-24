<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessSubscriptionRenewal implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $subscriptions = Subscription::with('plan')
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereDate('next_billing_date', '<=', today())
            ->get();

        foreach ($subscriptions as $subscription) {

            DB::transaction(function () use ($subscription) {

                $amount = $subscription->plan->monthly_price;

                $invoice = Invoice::create([
                    'tenant_id' => $subscription->tenant_id,
                    'subscription_id' => $subscription->subscription_id,
                    'invoice_number' => $this->generateInvoiceNumber(),
                    'invoice_date' => now(),
                    'due_date' => now()->addDays(7),
                    'subtotal' => $amount,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total_amount' => $amount,
                    'amount_paid' => 0,
                    'amount_due' => $amount,
                    'status' => 'pending',
                ]);

                $invoice->items()->create([
                    'description' => "Renewal - {$subscription->plan->plan_name}",
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'line_total' => $amount,
                    'period_start' => $subscription->end_date,
                    'period_end' => $subscription->end_date->copy()->addMonth(),
                ]);

                $subscription->update([
                    'next_billing_date' => now()->addMonth(),
                ]);

                Log::info('Renewal invoice generated', [
                    'subscription_id' => $subscription->subscription_id,
                    'invoice_id' => $invoice->invoice_id,
                ]);
            });
        }
    }

    private function generateInvoiceNumber(): string
    {
        return 'INV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}