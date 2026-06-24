<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    /**
     * ════════════════════════════════════════════════════════════
     * RECORD PAYMENT (Support Multiple Payments)
     * ════════════════════════════════════════════════════════════
     */
    public function recordPayment(
        Invoice $invoice,
        float $amount,
        string $paymentMethodId,
        ?string $gatewayId = null,
        ?string $paymentReference = null
    ): Payment {
        return DB::transaction(function () use (
            $invoice,
            $amount,
            $paymentMethodId,
            $gatewayId,
            $paymentReference
        ) {
            // Validate amount
            if ($amount > $invoice->amount_due) {
                throw new \Exception('Payment amount exceeds amount due');
            }

            if ($amount <= 0) {
                throw new \Exception('Payment amount must be greater than 0');
            }

            // Create payment record
            $payment = Payment::create([
                'invoice_id' => $invoice->invoice_id,
                'method_id' => $paymentMethodId,
                'gateway_id' => $gatewayId,
                'amount' => $amount,
                'payment_date' => now(),
                'payment_type' => 'subscription',
                'status' => 'completed',
                'payment_reference' => $paymentReference ?? 'PAY-' . uniqid(),
            ]);

            // Update invoice amounts
            $newAmountPaid = $invoice->amount_paid + $amount;
            $newAmountDue = $invoice->total_amount - $newAmountPaid;

            $invoice->update([
                'amount_paid' => $newAmountPaid,
                'amount_due' => $newAmountDue,
                'status' => $newAmountDue <= 0 ? 'paid' : 'sent',
            ]);

            // Activate subscription after full payment
            if ($newAmountDue <= 0) {

                $subscription = $invoice->subscription;

                if ($subscription) {

                    $subscription->update([
                        'status' => Subscription::STATUS_ACTIVE,
                        'start_date' => now(),
                    ]);

                    Log::info('Subscription activated', [
                        'subscription_id' => $subscription->subscription_id,
                    ]);
                }
            }

            return $payment->load(['invoice', 'method', 'gateway']);
        });
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PROCESS REFUND (Support Multiple Refunds)
     * ════════════════════════════════════════════════════════════
     */
    public function processRefund(
        Invoice $invoice,
        float $refundAmount,
        string $paymentMethodId,
        ?string $gatewayId = null,
        ?string $reason = null
    ): Payment {
        return DB::transaction(function () use (
            $invoice,
            $refundAmount,
            $paymentMethodId,
            $gatewayId,
            $reason
        ) {
            // Validate refund amount
            if ($refundAmount > $invoice->amount_paid) {
                throw new \Exception('Refund amount exceeds amount paid');
            }

            if ($refundAmount <= 0) {
                throw new \Exception('Refund amount must be greater than 0');
            }

            // Create refund payment (negative amount)
            $refund = Payment::create([
                'invoice_id' => $invoice->invoice_id,
                'method_id' => $paymentMethodId,
                'gateway_id' => $gatewayId,
                'amount' => -$refundAmount, // Negative for refund
                'payment_date' => now(),
                'payment_type' => 'subscription',
                'status' => 'refunded',
                'payment_reference' => 'REF-' . uniqid(),
                'gateway_response' => $reason ? ['reason' => $reason] : null,
            ]);

            // Update invoice amounts
            $newAmountPaid = $invoice->amount_paid - $refundAmount;
            $newAmountDue = $invoice->total_amount - $newAmountPaid;

            $invoice->update([
                'amount_paid' => $newAmountPaid,
                'amount_due' => $newAmountDue,
                'status' => $newAmountDue > 0 ? 'sent' : 'paid',
            ]);
            if ($newAmountPaid <= 0) {
                $subscription = $invoice->subscription;

                if ($subscription) {

                    $subscription->update([
                        'status' => Subscription::STATUS_SUSPENDED,
                    ]);

                    $subscription->tenant()->update([
                        'status' => 'suspended',
                    ]);

                    Log::warning('Subscription suspended after refund', [
                        'subscription_id' => $subscription->subscription_id,
                    ]);
                }
            }

            return $refund->load(['invoice', 'method', 'gateway']);
        });
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GET PAYMENT SUMMARY FOR INVOICE
     * ════════════════════════════════════════════════════════════
     */
    public function getPaymentSummary(Invoice $invoice): array
    {
        $payments = $invoice->payments()
            ->with(['method', 'gateway'])
            ->orderBy('payment_date')
            ->get();

        $totalPaid = $payments->where('status', 'completed')
            ->where('amount', '>', 0)
            ->sum('amount');

        $totalRefunded = abs($payments->where('status', 'refunded')
            ->sum('amount'));

        return [
            'total_paid' => $totalPaid,
            'total_refunded' => $totalRefunded,
            'net_paid' => $totalPaid - $totalRefunded,
            'payment_count' => $payments->where('amount', '>', 0)->count(),
            'refund_count' => $payments->where('amount', '<', 0)->count(),
            'payments' => $payments,
        ];
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GENERATE INVOICE
     * ════════════════════════════════════════════════════════════
     */
    public function generateInvoice(
        string $tenantId,
        array $items,
        string $invoiceType = 'subscription',
        ?Carbon $billingPeriodStart = null,
        ?Carbon $billingPeriodEnd = null
    ): Invoice {
        return DB::transaction(function () use (
            $tenantId,
            $items,
            $invoiceType,
            $billingPeriodStart,
            $billingPeriodEnd
        ) {
            // Calculate totals
            $subtotal = collect($items)->sum('amount');
            $taxAmount = $this->calculateTax($subtotal);
            $totalAmount = $subtotal + $taxAmount;

            // Create invoice
            $invoice = Invoice::create([
                'tenant_id' => $tenantId,
                'invoice_number' => $this->generateInvoiceNumber(),
                'invoice_type' => $invoiceType,
                'issue_date' => now(),
                'due_date' => now()->addDays(15), // 15 days payment term
                'billing_period_start' => $billingPeriodStart,
                'billing_period_end' => $billingPeriodEnd,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'balance' => $totalAmount,
                'status' => 'pending',
            ]);

            // Create invoice items
            foreach ($items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->invoice_id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount'],
                ]);
            }

            return $invoice->load('items');
        });
    }

    /**
     * ════════════════════════════════════════════════════════════
     * EXISTING METHODS
     * ════════════════════════════════════════════════════════════
     */
    
    public function generateSubscriptionInvoice(Subscription $subscription): Invoice
    {
        return DB::transaction(function () use ($subscription) {
            $plan = $subscription->plan;

            $periodStart = $subscription->next_billing_date ?? now();
            $periodEnd = $subscription->billing_cycle === 'yearly' ? 
                         $periodStart->copy()->addYear()->subDay() : 
                         $periodStart->copy()->addMonth()->subDay();

            $unitPrice = $subscription->billing_cycle === 'yearly' ? 
                        $plan->yearly_price : 
                        $plan->monthly_price;

            $subtotal = $unitPrice;
            $taxAmount = $this->calculateTax($subtotal);
            $totalAmount = $subtotal + $taxAmount;

            $invoice = Invoice::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->subscription_id,
                'invoice_number' => $this->generateInvoiceNumber(),
                'invoice_date' => now(),
                'due_date' => now()->addDays(15),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => 0,
                'total_amount' => $totalAmount,
                'amount_paid' => 0,
                'amount_due' => $totalAmount,
                'status' => 'sent',
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->invoice_id,
                'description' => "{$plan->plan_name} - {$subscription->billing_cycle} subscription",
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'line_total' => $totalAmount,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            return $invoice->load(['items', 'subscription', 'tenant']);
        });
    }

    /**
     * ════════════════════════════════════════════════════════════
     * MARK INVOICE AS PAID
     * ════════════════════════════════════════════════════════════
     */
    public function markAsPaid(
        Invoice $invoice,
        float $paidAmount,
        ?string $paymentMethod = null,
        ?string $paymentReference = null
    ): Invoice {
        $newAmountPaid = $invoice->amount_paid + $paidAmount;
        $newAmountDue = $invoice->total_amount - $newAmountPaid;

        $invoice->update([
            'amount_paid' => $newAmountPaid,
            'amount_due' => $newAmountDue,
            'status' => $newAmountDue <= 0 ? 'paid' : 'sent',
        ]);

        // បង្កើត Payment record (តាម ERD)
        if ($paymentMethod) {
            Payment::create([
                'invoice_id' => $invoice->invoice_id,
                'amount' => $paidAmount,
                'payment_date' => now(),
                'payment_type' => 'subscription',
                'status' => 'completed',
                'payment_reference' => $paymentReference,
            ]);
        }

        return $invoice->fresh();
    }

    public function detectOverdueInvoices(): int
    {
        $invoices = Invoice::where('status', 'sent')
            ->where('due_date', '<', now())
            ->with('subscription')
            ->get();

        foreach ($invoices as $invoice) {

            $invoice->update([
                'status' => 'overdue',
            ]);

            if ($invoice->subscription) {

                $invoice->subscription->update([
                    'status' => Subscription::STATUS_SUSPENDED,
                ]);

                $invoice->subscription->tenant()->update([
                    'status' => 'suspended',
                ]);
            }
        }

        return $invoices->count();
    }

    public function sendInvoice(Invoice $invoice): bool
    {
        if ($invoice->status !== 'draft') {
            throw new \Exception('Only draft invoices can be sent');
        }

        $invoice->update(['status' => 'sent']);

        return true;
    }

    public function cancelInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->status === 'paid') {
            throw new \Exception('Cannot cancel paid invoice');
        }

        $invoice->update([
            'status' => 'cancelled'
        ]);

        if ($invoice->subscription) {

            $invoice->subscription->update([
                'status' => Subscription::STATUS_CANCELLED
            ]);

            $invoice->subscription->tenant()->update([
                'status' => 'suspended'
            ]);
        }

        return $invoice->fresh();
    }

    protected function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = date('Ymd');
        
        $lastInvoice = Invoice::whereDate('created_at', today())
            ->orderBy('created_at', 'desc')
            ->first();

        $sequence = $lastInvoice ? 
                   intval(substr($lastInvoice->invoice_number, -4)) + 1 : 1;

        return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
    }

    protected function calculateTax(float $amount, float $rate = 10): float
    {
        return round(($amount * $rate) / 100, 2);
    }
}