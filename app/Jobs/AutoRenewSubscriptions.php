<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Models\Invoice;
use App\Notifications\SubscriptionRenewedNotification;
use App\Notifications\SubscriptionRenewalFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoRenewSubscriptions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * AUTO RENEW SUBSCRIPTIONS JOB
     * 
     * គោលបំណង: ធ្វើឱ្យជាវដោយស្វ័យប្រវត្តិ
     * 
     * Schedule: ជារៀងរាល់ថ្ងៃ នៅម៉ោង 00:00 (Midnight)
     * 
     * Process:
     * ① រកជាវដែលជិតផុតកំណត់ (end_date <= tomorrow)
     * ② ពិនិត្យមើល auto_renew = true
     * ③ បង្កើត invoice ថ្មី
     * ④ បន្តជាវ
     * ⑤ ផ្ញើជូនដំណឹង
     */
    public function handle(): void
    {
        Log::info('========================================');
        Log::info('Auto Renewal Process Started');
        Log::info('========================================');

        // ① រកជាវដែលត្រូវបន្ត (expiring tomorrow or today)
        $subscriptions = Subscription::with(['plan', 'tenant'])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('auto_renew', true)
            ->where('end_date', '<=', now()->addDay())
            ->where('end_date', '>=', now())
            ->get();

        Log::info("Found {$subscriptions->count()} subscriptions to renew");

        $renewed = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            try {
                Log::info("Processing subscription: {$subscription->subscription_id}", [
                    'tenant' => $subscription->tenant->company_name,
                    'plan' => $subscription->plan->plan_name,
                ]);

                $this->renewSubscription($subscription);
                $renewed++;

            } catch (\Exception $e) {
                $failed++;
                
                Log::error("Failed to renew subscription: {$subscription->subscription_id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Notify tenant about failure
                $this->notifyRenewalFailed($subscription, $e->getMessage());
            }
        }

        Log::info('========================================');
        Log::info('Auto Renewal Process Completed');
        Log::info("Renewed: {$renewed}");
        Log::info("Failed: {$failed}");
        Log::info('========================================');
    }

    /**
     * RENEW SUBSCRIPTION - បន្តជាវ
     */
    protected function renewSubscription(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            
            // គណនាកាលបរិច្ឆេទថ្មី
            $currentEndDate = $subscription->end_date;
            $billingCycle = $currentEndDate->diffInDays($subscription->start_date) > 32 
                ? 'yearly' 
                : 'monthly';

            $newStartDate = $currentEndDate->copy();
            $newEndDate = $billingCycle === 'yearly' 
                ? $newStartDate->copy()->addYear() 
                : $newStartDate->copy()->addMonth();
            $newBillingDate = $newEndDate->copy();

            // គណនាតម្លៃ
            $amount = $billingCycle === 'yearly' 
                ? $subscription->plan->yearly_price 
                : $subscription->plan->monthly_price;

            // បង្កើត Invoice
            $invoice = $this->createRenewalInvoice($subscription, $amount, $billingCycle);

            // ផ្ញើជូនដំណឹង
            $this->notifyRenewalSuccess($subscription, $invoice);

            Log::info("Subscription renewed successfully", [
                'subscription_id' => $subscription->subscription_id,
                'new_end_date' => $newEndDate->format('Y-m-d'),
                'invoice_id' => $invoice->invoice_id,
            ]);
        });
    }

    /**
     * CREATE RENEWAL INVOICE - បង្កើត Invoice
     */
    protected function createRenewalInvoice(
        Subscription $subscription,
        float $amount,
        string $billingCycle
    ): Invoice {
        $invoiceNumber = $this->generateInvoiceNumber();

        $invoice = Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->subscription_id,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => now(),
            'due_date' => now()->addDays(7),
            'subtotal' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'amount_paid' => 0,
            'amount_due' => $amount,
            'status' => 'sent',
        ]);
        $invoice->items()->create([
            'description' => "Renewal -" ?? $billingCycle . " - " . $subscription->plan->plan_name,
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
            'period_start' => $subscription->end_date,
            'period_end' => $subscription->end_date->copy()->addMonth(),
        ]);
        return $invoice;
    }

    /**
     * NOTIFY SUCCESS - ជូនដំណឹងជោគជ័យ
     */
    protected function notifyRenewalSuccess(Subscription $subscription, Invoice $invoice): void
    {
        $managers = \App\Models\User::where('tenant_id', $subscription->tenant_id)
            ->whereIn('role', ['admin', 'manager'])
            ->where('is_active', true)
            ->get();

        foreach ($managers as $manager) {
            try {
                $manager->notify(new SubscriptionRenewedNotification($subscription, $invoice));
            } catch (\Exception $e) {
                Log::error("Failed to send renewal notification", [
                    'user' => $manager->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * NOTIFY FAILURE - ជូនដំណឹងបរាជ័យ
     */
    protected function notifyRenewalFailed(Subscription $subscription, string $reason): void
    {
        $managers = \App\Models\User::where('tenant_id', $subscription->tenant_id)
            ->whereIn('role', ['admin', 'manager'])
            ->where('is_active', true)
            ->get();

        foreach ($managers as $manager) {
            try {
                $manager->notify(new SubscriptionRenewalFailedNotification($subscription, $reason));
            } catch (\Exception $e) {
                Log::error("Failed to send renewal failed notification", [
                    'user' => $manager->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Generate invoice number
     */
    protected function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = now()->format('Ymd');
        $random = strtoupper(\Illuminate\Support\Str::random(6));
        
        return "{$prefix}-{$date}-{$random}";
    }
}