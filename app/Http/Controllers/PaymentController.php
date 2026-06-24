<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Http\Resources\PaymentResource;
use App\Services\Gateways\KHQRPaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * ════════════════════════════════════════════════════════════
     * INITIATE INVOICE PAYMENT
     * ════════════════════════════════════════════════════════════
     */
    public function initiateInvoicePayment(Request $request, string $invoiceId)
    {
        $validated = $request->validate([
            'gateway_code' => 'required|string|in:aba,wing',
            'payment_method_id' => 'required|uuid|exists:payment_methods,method_id',
            'currency' => 'nullable|in:USD,KHR',
            'return_url' => 'nullable|url',
            'cancel_url' => 'nullable|url',
            'success_url' => 'nullable|url',
        ]);

        $tenant = app('tenant');
        
        $invoice = Invoice::where('tenant_id', $tenant->tenant_id)
            ->findOrFail($invoiceId);

        try {
            $result = $this->paymentService->initiateInvoicePayment(
                invoice: $invoice,
                gatewayCode: $validated['gateway_code'],
                paymentMethodId: $validated['payment_method_id'],
                additionalData: [
                    'currency' => $validated['currency'] ?? 'USD',
                    'return_url' => $validated['return_url'] ?? null,
                    'cancel_url' => $validated['cancel_url'] ?? null,
                    'success_url' => $validated['success_url'] ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment initiated successfully',
                'data' => [
                    'payment' => new PaymentResource($result['payment']),
                    'payment_url' => $result['payment_url'],
                    'gateway_transaction_id' => $result['gateway_transaction_id'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to initiate invoice payment', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * INITIATE POS PAYMENT
     * ════════════════════════════════════════════════════════════
     */
    public function initiatePOSPayment(Request $request, string $transactionId)
    {
        $validated = $request->validate([
            'gateway_code' => 'required|string|in:aba,wing',
            'payment_method_id' => 'required|uuid|exists:payment_methods,method_id',
            'currency' => 'nullable|in:USD,KHR',
            'return_url' => 'nullable|url',
        ]);

        $tenant = app('tenant');
        
        $transaction = Transaction::whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->findOrFail($transactionId);

        try {
            $result = $this->paymentService->initiatePOSPayment(
                transaction: $transaction,
                gatewayCode: $validated['gateway_code'],
                paymentMethodId: $validated['payment_method_id'],
                additionalData: [
                    'currency' => $validated['currency'] ?? 'USD',
                    'return_url' => $validated['return_url'] ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment initiated successfully',
                'data' => [
                    'payment' => new PaymentResource($result['payment']),
                    'payment_url' => $result['payment_url'],
                    'gateway_transaction_id' => $result['gateway_transaction_id'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to initiate POS payment', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * CHECK PAYMENT STATUS
     * ════════════════════════════════════════════════════════════
     */
    public function checkStatus(string $paymentId)
    {
        $payment = Payment::findOrFail($paymentId);

        try {
            $result = $this->paymentService->checkPaymentStatus($payment);

            return response()->json([
                'success' => $result['success'],
                'data' => [
                    'status' => $result['status'] ?? null,
                    'payment' => new PaymentResource($result['payment'] ?? $payment),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PROCESS REFUND
     * ════════════════════════════════════════════════════════════
     */
    public function refund(Request $request, string $paymentId)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string',
        ]);

        $payment = Payment::findOrFail($paymentId);

        try {
            $refundPayment = $this->paymentService->processRefund(
                originalPayment: $payment,
                refundAmount: $validated['amount'],
                reason: $validated['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Refund processed successfully',
                'data' => [
                    'refund' => new PaymentResource($refundPayment),
                    'original_payment' => new PaymentResource($payment->fresh()),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process refund', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PAYMENT RETURN (from gateway)
     * ════════════════════════════════════════════════════════════
     */
    public function return(Request $request, string $paymentId)
    {
        $payment = Payment::with(['invoice', 'transaction'])->findOrFail($paymentId);

        // Redirect based on payment type and status
        if ($payment->status === 'completed') {
            if ($payment->invoice_id) {
                return redirect()->route('invoices.show', $payment->invoice_id)
                    ->with('success', 'Payment completed successfully!');
            } else {
                return redirect()->route('transactions.show', $payment->transaction_id)
                    ->with('success', 'Payment completed successfully!');
            }
        }

        return redirect()->route('payments.status', $paymentId)
            ->with('info', 'Payment is being processed...');
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PAYMENT SUCCESS
     * ════════════════════════════════════════════════════════════
     */
    public function success(string $paymentId)
    {
        $payment = Payment::with(['invoice', 'transaction'])->findOrFail($paymentId);

        return view('payments.success', compact('payment'));
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PAYMENT CANCEL
     * ════════════════════════════════════════════════════════════
     */
    public function cancel(string $paymentId)
    {
        $payment = Payment::with(['invoice', 'transaction'])->findOrFail($paymentId);

        $payment->update(['status' => 'failed']);

        return view('payments.cancel', compact('payment'));
    }

    /**
     * ════════════════════════════════════════════════════════════
     * initiate KHQR Payment
     * ════════════════════════════════════════════════════════════
     */
    public function initiateKHQRPayment(Request $request, string $invoiceId)
    {
        $validated = $request->validate([
            'currency' => 'nullable|in:KHR,USD',
        ]);

        $tenant = app('tenant');
        
        $invoice = Invoice::where('tenant_id', $tenant->tenant_id)
            ->findOrFail($invoiceId);

        try {
            $result = $this->paymentService->initiateInvoicePayment(
                invoice: $invoice,
                gatewayCode: 'khqr',  // ← Fixed to KHQR
                paymentMethodId: null, // KHQR doesn't need method
                additionalData: [
                    'currency' => $validated['currency'] ?? 'KHR',
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'KHQR QR code generated',
                'data' => [
                    'payment' => new PaymentResource($result['payment']),
                    'qr_image_url' => $result['payment_url'],
                    'qr_code_string' => $result['qr_code'] ?? null,
                    'qr_code_id' => $result['gateway_transaction_id'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate KHQR', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    
    /**
     * ════════════════════════════════════════════════════════════
     * Check KHQR Status
     * ════════════════════════════════════════════════════════════
     */
    public function checkKHQRStatus(string $paymentId)
    {
        $payment = Payment::findOrFail($paymentId);

        if ($payment->gateway->gateway_code !== 'khqr') {
            return response()->json([
                'success' => false,
                'message' => 'Not a KHQR payment',
            ], 400);
        }

        try {
            $gateway = new KHQRPaymentGateway($payment->gateway);
            
            $statusResult = $gateway->getPaymentStatus(
                $payment->gateway_transaction_id
            );

            // Update payment if status changed
            if ($statusResult['status'] !== $payment->status) {
                $payment->update([
                    'status' => $statusResult['status'],
                    'payment_date' => $statusResult['paid_at'] ?? $payment->payment_date,
                ]);

                if ($statusResult['status'] === 'completed') {
                    // Handle successful payment
                    $this->paymentService->handleSuccessfulPayment($payment);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $statusResult['status'],
                    'payment' => new PaymentResource($payment->fresh()),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}