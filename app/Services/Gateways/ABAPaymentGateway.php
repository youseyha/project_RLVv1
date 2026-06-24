<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ABAPaymentGateway implements PaymentGatewayInterface
{
    protected $gateway;
    protected $merchantId;
    protected $apiKey;
    protected $apiEndpoint;

    public function __construct(PaymentGateway $gateway)
    {
        $this->gateway = $gateway;
        
        $credentials = json_decode(
            decrypt($gateway->api_credentials_encrypted),
            true
        );

        $this->merchantId = $credentials['merchant_id'];
        $this->apiKey = $credentials['api_key'];
        $this->apiEndpoint = $gateway->api_endpoint;
    }

    /**
     * ════════════════════════════════════════════════════════════
     * CREATE PAYMENT SESSION (ABA Purchase/Payment)
     * Step 1: Initiate payment with ABA
     * ════════════════════════════════════════════════════════════
     */
    public function initiatePayment(array $data): array
    {
        try {
            Log::info('ABA: Creating payment session', [
                'amount' => $data['amount'],
                'payment_id' => $data['payment_id'],
            ]);

            // Generate transaction reference
            $tranRef = $this->generateTransactionReference();

            // Prepare payment data
            $paymentParams = [
                'merchant_id' => $this->merchantId,
                'tran_id' => $tranRef,
                'amount' => (int) round($data['amount'] * 100), // Convert to cents
                'currency' => $data['currency'] ?? 'USD',
                'description' => $data['description'] ?? 'Payment',
                'return_url' => $data['return_url'],
                'cancel_url' => $data['cancel_url'],
                'callback_url' => $data['callback_url'],
                'req_time' => now()->timestamp,
                'hash_value' => '',
            ];

            // Generate SHA512 hash signature
            $paymentParams['hash_value'] = $this->generateSignature($paymentParams);

            // Call ABA API to create payment session
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ])
                ->post("{$this->apiEndpoint}/payment", $paymentParams);

            if (!$response->successful()) {
                Log::error('ABA: API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'status' => 'failed',
                    'error' => 'ABA API error: ' . $response->status(),
                ];
            }

            // Parse ABA response
            $result = $response->json();

            if (isset($result['error_code']) && $result['error_code'] != 0) {
                Log::error('ABA: Payment error', [
                    'error_code' => $result['error_code'],
                    'error_message' => $result['error_message'] ?? '',
                ]);

                return [
                    'status' => 'failed',
                    'error' => $result['error_message'] ?? 'Unknown error',
                ];
            }

            Log::info('ABA: Payment session created', [
                'payment_id' => $data['payment_id'],
                'tran_id' => $tranRef,
                'payment_url' => $result['redirect_url'] ?? '',
            ]);

            return [
                'status' => 'success',
                'transaction_id' => $tranRef,
                'payment_url' => $result['redirect_url'] ?? "{$this->apiEndpoint}/checkout/{$tranRef}",
                'gateway_response' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('ABA: Payment initiation failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * VERIFY PAYMENT (Check status via API)
     * Step 2: Confirm payment was successful
     * ════════════════════════════════════════════════════════════
     */
    public function verifyPayment(array $data): array
    {
        try {
            Log::info('ABA: Verifying payment', [
                'tran_id' => $data['tran_id'] ?? $data['transaction_id'],
            ]);

            $tranId = $data['tran_id'] ?? $data['transaction_id'];

            // Prepare query parameters
            $queryParams = [
                'merchant_id' => $this->merchantId,
                'tran_id' => $tranId,
                'req_time' => now()->timestamp,
                'hash_value' => '',
            ];

            // Generate signature
            $queryParams['hash_value'] = $this->generateSignature($queryParams);

            // Call ABA API to check status
            $response = Http::timeout(30)
                ->asForm()
                ->post("{$this->apiEndpoint}/query", $queryParams);

            if (!$response->successful()) {
                return [
                    'status' => 'failed',
                    'error' => 'Unable to verify payment',
                ];
            }

            $result = $response->json();

            // Map ABA status to our status
            $status = match($result['status'] ?? '2') {
                '0' => 'completed',  // Approved
                '1' => 'pending',    // Pending
                '2' => 'failed',     // Failed/Declined
                default => 'unknown',
            };

            Log::info('ABA: Payment verified', [
                'tran_id' => $tranId,
                'status' => $status,
                'amount' => $result['amount'] ?? 0,
            ]);

            return [
                'status' => $status,
                'transaction_id' => $tranId,
                'amount' => ($result['amount'] ?? 0) / 100, // Convert back to normal
                'paid_at' => $result['paid_at'] ?? now(),
                'raw_data' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('ABA: Payment verification failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PROCESS REFUND
     * Step 3: Refund payment back to customer
     * ════════════════════════════════════════════════════════════
     */
    public function processRefund(string $transactionId, float $amount): array
    {
        try {
            Log::warning('ABA: Processing refund', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
            ]);

            // Generate refund reference
            $refundRef = 'REF-' . $transactionId . '-' . uniqid();

            // Prepare refund parameters
            $refundParams = [
                'merchant_id' => $this->merchantId,
                'tran_id' => $transactionId,
                'refund_amount' => (int) round($amount * 100),
                'req_time' => now()->timestamp,
                'hash_value' => '',
            ];

            // Generate signature
            $refundParams['hash_value'] = $this->generateSignature($refundParams);

            // Call ABA refund API
            $response = Http::timeout(30)
                ->asForm()
                ->post("{$this->apiEndpoint}/refund", $refundParams);

            if (!$response->successful()) {
                Log::error('ABA: Refund API error', [
                    'status' => $response->status(),
                ]);

                return [
                    'status' => 'failed',
                    'error' => 'Refund API error',
                ];
            }

            $result = $response->json();

            // Check if refund was successful
            if (isset($result['status']) && $result['status'] == '0') {
                Log::info('ABA: Refund processed', [
                    'transaction_id' => $transactionId,
                    'refund_amount' => $amount,
                ]);

                return [
                    'status' => 'completed',
                    'refund_id' => $refundRef,
                    'gateway_response' => $result,
                ];
            }

            return [
                'status' => 'pending',
                'refund_id' => $refundRef,
                'note' => 'Refund pending approval',
            ];

        } catch (\Exception $e) {
            Log::error('ABA: Refund processing failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GET PAYMENT STATUS (Polling)
     * ════════════════════════════════════════════════════════════
     */
    public function getPaymentStatus(string $transactionId): array
    {
        return $this->verifyPayment([
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * VERIFY WEBHOOK SIGNATURE
     * ABA uses SHA512 hash
     * ════════════════════════════════════════════════════════════
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        try {
            // Sort payload by key
            ksort($payload);

            // Build string to hash (exclude hash_value)
            $hashString = '';
            foreach ($payload as $key => $value) {
                if ($key !== 'hash_value' && $key !== 'hash') {
                    $hashString .= $key . '=' . $value;
                }
            }

            // Add API key
            $hashString .= $this->apiKey;

            // Calculate SHA512 hash
            $calculatedSignature = strtolower(hash('sha512', $hashString));

            // Compare using timing-safe comparison
            $isValid = hash_equals($calculatedSignature, strtolower($signature));

            if (!$isValid) {
                Log::warning('ABA: Invalid webhook signature', [
                    'expected' => $calculatedSignature,
                    'received' => $signature,
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('ABA: Signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * HELPER METHODS
     * ════════════════════════════════════════════════════════════
     */

    public function getName(): string
    {
        return 'ABA PayWay';
    }

    public function getCode(): string
    {
        return 'aba';
    }

    /**
     * Generate SHA512 signature for ABA
     */
    protected function generateSignature(array $params): string
    {
        // Sort parameters
        ksort($params);

        // Build string
        $signString = '';
        foreach ($params as $key => $value) {
            if ($key !== 'hash_value' && $key !== 'hash') {
                $signString .= $key . '=' . $value;
            }
        }

        // Add API key
        $signString .= $this->apiKey;

        // Generate SHA512 hash
        return strtolower(hash('sha512', $signString));
    }

    /**
     * Generate unique transaction reference
     */
    protected function generateTransactionReference(): string
    {
        $prefix = 'ABA';
        $timestamp = time();
        $random = strtoupper(substr(uniqid(), -6));

        return "{$prefix}-{$timestamp}-{$random}";
    }
}