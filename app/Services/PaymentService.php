<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Contracts\PaymentGatewayInterface;
use App\Services\Gateways\ABAPaymentGateway;
use App\Services\Gateways\KHQRPaymentGateway;
use App\Services\Gateways\WingPaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * ════════════════════════════════════════════════════════════
     * GET GATEWAY INSTANCE
     * ════════════════════════════════════════════════════════════
     */
    protected function getGatewayInstance(string $gatewayCode): PaymentGatewayInterface
    {
        $gateway = PaymentGateway::where('gateway_code', $gatewayCode)
            ->where('status', 'active')
            ->firstOrFail();

        return match($gatewayCode) {
            'aba' => new ABAPaymentGateway($gateway),
            'wing' => new WingPaymentGateway($gateway),
            'khqr' => new KHQRPaymentGateway($gateway),
            default => throw new \Exception("Unsupported gateway: {$gatewayCode}"),
        };
    }

    /**
     * ════════════════════════════════════════════════════════════
     * INITIATE PAYMENT FOR INVOICE
     * ════════════════════════════════════════════════════════════
     */
    public function initiateInvoicePayment(
        Invoice $invoice,
        string $gatewayCode,
        ?string $paymentMethodId,
        array $additionalData = []
    ): array {
        return DB::transaction(function () use ($invoice, $gatewayCode, $paymentMethodId, $additionalData) {
            
            // Validate invoice
            if ($invoice->status === 'paid') {
                throw new \Exception('Invoice is already paid');
            }

            if ($invoice->amount_due <= 0) {
                throw new \Exception('No amount due for this invoice');
            }
            // KHQR doesn't need payment method
            if ($gatewayCode === 'khqr') {
                // Skip payment method validation
                $paymentMethod = null; 
            } else {
                // For ABA/Wing, payment method is required
                if (!$paymentMethodId) {
                    throw new \Exception('Payment method required for this gateway');
                }

                $paymentMethod = PaymentMethod::findOrFail($paymentMethodId);
            }

            // Get gateway instance
            $gateway = $this->getGatewayInstance($gatewayCode);
            $gatewayModel = PaymentGateway::where('gateway_code', $gatewayCode)->firstOrFail();

            // Create pending payment record
            $payment = Payment::create([
                'method_id' => $paymentMethod?->method_id,// ← Nullable for KHQR
                'gateway_id' => $gatewayModel->gateway_id,
                'invoice_id' => $invoice->invoice_id,
                'payment_reference' => 'PENDING-' . uniqid(),
                'amount' => $invoice->amount_due,
                'payment_date' => now(),
                'payment_type' => 'subscription',
                'status' => 'pending',
                'method_snapshot' => $paymentMethod->toArray(),
                'gateway_snapshot' => $gatewayModel->toArray(),
            ]);

            // Prepare payment data
            $paymentData = [
                'amount' => $invoice->amount_due,
                'currency' => $additionalData['currency'] ?? 'USD',
                'description' => "Invoice {$invoice->invoice_number}",
                'return_url' => $additionalData['return_url'] ?? route('payment.return', $payment->payment_id),
                'cancel_url' => $additionalData['cancel_url'] ?? route('payment.cancel', $payment->payment_id),
                'success_url' => $additionalData['success_url'] ?? route('payment.success', $payment->payment_id),
                'callback_url' => route('webhook.' . $gatewayCode),
                'customer_name' => $invoice->tenant->company_name,
                'customer_email' => $invoice->tenant->email,
                'customer_phone' => $invoice->tenant->phone,
                'payment_id' => $payment->payment_id,
            ];

            // Add URLs for non-KHQR gateways
            if ($gatewayCode !== 'khqr') {
                $paymentData['return_url'] = $additionalData['return_url'] ?? route('payment.return', $payment->payment_id);
                $paymentData['cancel_url'] = $additionalData['cancel_url'] ?? route('payment.cancel', $payment->payment_id);
            }

            // Initiate payment with gateway
            $gatewayResponse = $gateway->initiatePayment($paymentData);

            // Update payment with gateway transaction ID
            $payment->update([
                'payment_reference' => $gatewayResponse['transaction_id'],
                'gateway_transaction_id' => $gatewayResponse['transaction_id'],
                'gateway_response' => json_encode($gatewayResponse['gateway_response'] ?? []),
                'status' => 'processing',
            ]);

            Log::info('Invoice payment initiated', [
                'payment_id' => $payment->payment_id,
                'invoice_id' => $invoice->invoice_id,
                'gateway' => $gatewayCode,
                'amount' => $invoice->amount_due,
            ]);

            return [
                'payment' => $payment->fresh(),
                'payment_url' => $gatewayResponse['payment_url'],
                'qr_image_url' => $gatewayResponse['qr_image_url'] ?? null,  // For KHQR
                'gateway_transaction_id' => $gatewayResponse['transaction_id'],
            ];
        });
    }

    /**
     * ════════════════════════════════════════════════════════════
     * INITIATE PAYMENT FOR POS TRANSACTION
     * ════════════════════════════════════════════════════════════
     */
    public function initiatePOSPayment(
        Transaction $transaction,
        string $gatewayCode,
        string $paymentMethodId,
        array $additionalData = []
    ): array {
        return DB::transaction(function () use ($transaction, $gatewayCode, $paymentMethodId, $additionalData) {
            
            // Validate transaction
            if ($transaction->status !== 'pending') {
                throw new \Exception('Transaction is not in pending status');
            }

            // Get gateway instance
            $gateway = $this->getGatewayInstance($gatewayCode);
            $gatewayModel = PaymentGateway::where('gateway_code', $gatewayCode)->firstOrFail();

            // Get payment method
            $paymentMethod = PaymentMethod::findOrFail($paymentMethodId);

            // Create pending payment record
            $payment = Payment::create([
                'method_id' => $paymentMethod->method_id,
                'gateway_id' => $gatewayModel->gateway_id,
                'transaction_id' => $transaction->transaction_id,
                'payment_reference' => 'PENDING-' . uniqid(),
                'amount' => $transaction->total_amount,
                'payment_date' => now(),
                'payment_type' => 'pos_transaction',
                'status' => 'pending',
                'method_snapshot' => $paymentMethod->toArray(),
                'gateway_snapshot' => $gatewayModel->toArray(),
            ]);

            // Prepare payment data
            $paymentData = [
                'amount' => $transaction->total_amount,
                'currency' => $additionalData['currency'] ?? 'USD',
                'description' => "Transaction {$transaction->transaction_number}",
                'return_url' => $additionalData['return_url'] ?? route('payment.return', $payment->payment_id),
                'callback_url' => route('webhook.' . $gatewayCode),
                'payment_id' => $payment->payment_id,
            ];

            // Initiate payment with gateway
            $gatewayResponse = $gateway->initiatePayment($paymentData);

            // Update payment
            $payment->update([
                'payment_reference' => $gatewayResponse['transaction_id'],
                'gateway_transaction_id' => $gatewayResponse['transaction_id'],
                'gateway_response' => json_encode($gatewayResponse['gateway_response'] ?? []),
                'status' => 'processing',
            ]);

            Log::info('POS payment initiated', [
                'payment_id' => $payment->payment_id,
                'transaction_id' => $transaction->transaction_id,
                'gateway' => $gatewayCode,
                'amount' => $transaction->total_amount,
            ]);

            return [
                'payment' => $payment->fresh(),
                'payment_url' => $gatewayResponse['payment_url'],
                'gateway_transaction_id' => $gatewayResponse['transaction_id'],
            ];
        });
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PROCESS WEBHOOK CALLBACK
     * ════════════════════════════════════════════════════════════
     */
    public function processWebhookCallback(
        string $gatewayCode,
        array $webhookData
    ): array {
        return DB::transaction(function () use ($gatewayCode, $webhookData) {
            
            Log::info('Processing webhook callback', [
                'gateway' => $gatewayCode,
                'has_data' => !empty($webhookData),
            ]);

            try {
                // Step 1: Get gateway instance
                $gateway = $this->getGatewayInstance($gatewayCode);

                // Step 2: Verify payment with gateway
                $verificationResult = $gateway->verifyPayment($webhookData);

                if ($verificationResult['status'] === 'failed') {
                    Log::error('Payment verification failed', [
                        'gateway' => $gatewayCode,
                        'error' => $verificationResult['error'] ?? 'Unknown',
                    ]);

                    throw new \Exception('Payment verification failed');
                }


                // Step 3: Find payment record
                $payment = $this->findPaymentRecord($gatewayCode, $webhookData);

                if (!$payment) {
                    Log::warning('Payment record not found', [
                        'gateway' => $gatewayCode,
                        'transaction_id' => $verificationResult['transaction_id'],
                    ]);

                    throw new \Exception('Payment record not found');
                }


                // Step 4: Update payment status
                $payment->update([
                    'status' => $verificationResult['status'],
                    'payment_date' => $verificationResult['paid_at'] ?? now(),
                    'gateway_response' => json_encode(
                        $verificationResult['raw_data'] ?? []
                    ),
                ]);


                // Step 5: If completed, handle success
                if ($verificationResult['status'] === 'completed') {
                    $this->handleSuccessfulPayment($payment->fresh());
                }

                Log::info('Webhook processed successfully', [
                    'payment_id' => $payment->payment_id,
                    'gateway' => $gatewayCode,
                    'status' => $verificationResult['status'],
                ]);

                return [
                    'success' => true,
                    'payment' => $payment->fresh(),
                    'status' => $verificationResult['status'],
                ];

            } catch (\Exception $e) {
                Log::error('Webhook processing error', [
                    'gateway' => $gatewayCode,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }


    /**
     * ════════════════════════════════════════════════════════════
     * HANDLE SUCCESSFUL PAYMENT
     * ════════════════════════════════════════════════════════════
     */
    public function handleSuccessfulPayment(Payment $payment): void
    {
        try {
            // Update invoice if exists
            if ($payment->invoice_id) {
                $invoice = $payment->invoice;

                $newAmountPaid = $invoice->amount_paid + $payment->amount;
                $newAmountDue = max(0, $invoice->total_amount - $newAmountPaid);

                $invoice->update([
                    'amount_paid' => $newAmountPaid,
                    'amount_due' => $newAmountDue,
                    'status' => $newAmountDue <= 0 ? 'paid' : 'sent',
                ]);

                // Activate subscription if trial completed
                if ($invoice->subscription_id && $newAmountDue <= 0) {
                    $subscription = $invoice->subscription;

                    if ($subscription->status === 'trial') {
                        $subscription->update([
                            'status' => 'active',
                            'next_billing_date' => now()->addMonth(),
                        ]);

                        Log::info('Subscription activated after payment', [
                            'subscription_id' => $subscription->subscription_id,
                        ]);
                    }
                }

                Log::info('Invoice updated after payment', [
                    'invoice_id' => $invoice->invoice_id,
                    'status' => $invoice->status,
                ]);
            }

            // Update transaction if exists
            if ($payment->transaction_id) {
                $transaction = $payment->transaction;

                $transaction->update(['status' => 'completed']);

                Log::info('Transaction completed', [
                    'transaction_id' => $transaction->transaction_id,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error handling successful payment', [
                'payment_id' => $payment->payment_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PROCESS REFUND
     * ════════════════════════════════════════════════════════════
     */
    public function processRefund(
        Payment $originalPayment,
        float $refundAmount,
        string $reason = "Refund requested by customer"
    ): Payment {
        return DB::transaction(function () use ($originalPayment, $refundAmount, $reason) {
            
            // Validate
            if ($originalPayment->status !== 'completed') {
                throw new \Exception('Can only refund completed payments');
            }

            if ($refundAmount > $originalPayment->amount) {
                throw new \Exception('Refund amount exceeds original payment amount');
            }

            // Get gateway
            $gateway = $this->getGatewayInstance($originalPayment->gateway->gateway_code);

            // Process refund with gateway
            $refundResult = $gateway->processRefund(
                $originalPayment->gateway_transaction_id,
                $refundAmount
            );

            if ($refundResult['status'] !== 'completed') {
                throw new \Exception('Gateway refund failed');
            }

            // Create refund payment record
            $refundPayment = Payment::create([
                'method_id' => $originalPayment->method_id,
                'gateway_id' => $originalPayment->gateway_id,
                'invoice_id' => $originalPayment->invoice_id,
                'transaction_id' => $originalPayment->transaction_id,
                'payment_reference' => $refundResult['refund_id'],
                'amount' => -$refundAmount, // Negative for refund
                'payment_date' => now(),
                'payment_type' => $originalPayment->payment_type,
                'status' => 'refunded',
                'gateway_transaction_id' => $refundResult['refund_id'],
                'gateway_response' => json_encode(['reason' => $reason]),
                'method_snapshot' => $originalPayment->method_snapshot,
                'gateway_snapshot' => $originalPayment->gateway_snapshot,
            ]);

            // Update original payment if full refund
            if ($refundAmount >= $originalPayment->amount) {
                $originalPayment->update(['status' => 'refunded']);
            }

            // Update invoice if applicable
            if ($originalPayment->invoice_id) {
                $invoice = $originalPayment->invoice;
                
                $newAmountPaid = $invoice->amount_paid - $refundAmount;
                $newAmountDue = $invoice->total_amount - $newAmountPaid;

                $invoice->update([
                    'amount_paid' => $newAmountPaid,
                    'amount_due' => $newAmountDue,
                    'status' => $newAmountDue > 0 ? 'sent' : 'paid',
                ]);
            }

            Log::info('Payment refunded successfully', [
                'original_payment_id' => $originalPayment->payment_id,
                'refund_payment_id' => $refundPayment->payment_id,
                'refund_amount' => $refundAmount,
            ]);

            return $refundPayment->load(['invoice', 'transaction', 'method', 'gateway']);
        });
    }

    /**
     * ════════════════════════════════════════════════════════════
     * CHECK PAYMENT STATUS
     * ════════════════════════════════════════════════════════════
     */
    public function checkPaymentStatus(Payment $payment): array
    {
        try {
            // Get gateway
            $gateway = $this->getGatewayInstance($payment->gateway->gateway_code);

            // Query payment status
            $statusResult = $gateway->getPaymentStatus($payment->gateway_transaction_id);

            // Update payment if status changed
            if ($statusResult['status'] !== $payment->status) {
                $payment->update([
                    'status' => $statusResult['status'],
                    'payment_date' => $statusResult['paid_at'] ?? $payment->payment_date,
                ]);

                if ($statusResult['status'] === 'completed') {
                    $this->handleSuccessfulPayment($payment);
                }
            }

            return [
                'success' => true,
                'status' => $statusResult['status'],
                'payment' => $payment->fresh(),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to check payment status', [
                'payment_id' => $payment->payment_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * HELPER: Find payment by gateway-specific ID
     * ════════════════════════════════════════════════════════════
     */
    protected function findPaymentRecord(
        string $gatewayCode,
        array $webhookData
    ): ?Payment {
        $transactionId = match($gatewayCode) {
            'aba' => $webhookData['tran_id'] ?? null,
            'wing' => $webhookData['wing_transaction_id'] ?? 
                     $webhookData['merchant_ref_id'] ?? null,
            'khqr' => $webhookData['qr_code_id'] ?? 
                     $webhookData['transaction_id'] ?? null,
            default => null,
        };

        if (!$transactionId) {
            return null;
        }

        return Payment::where('gateway_transaction_id', $transactionId)
            ->orWhere('payment_reference', $transactionId)
            ->first();
    }
}