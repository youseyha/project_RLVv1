<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WingPaymentGateway implements PaymentGatewayInterface
{
    protected $gateway;
    protected $merchantCode;
    protected $apiToken;
    protected $secretKey;
    protected $apiEndpoint;

    public function __construct(PaymentGateway $gateway)
    {
        $this->gateway = $gateway;

        $credentials = json_decode(
            decrypt($gateway->api_credentials_encrypted),
            true
        );

        $this->merchantCode = $credentials['merchant_code'];
        $this->apiToken = $credentials['api_token'];
        $this->secretKey = $credentials['secret_key'];
        $this->apiEndpoint = $gateway->api_endpoint;
    }

    /**
     * ════════════════════════════════════════════════════════════
     * CREATE PAYMENT SESSION (Wing)
     * ════════════════════════════════════════════════════════════
     */
    public function initiatePayment(array $data): array
    {
        try {
            Log::info('Wing: Creating payment session', [
                'amount' => $data['amount'],
                'payment_id' => $data['payment_id'],
            ]);

            // Generate reference ID
            $referenceId = 'WING-' . time() . '-' . uniqid();

            // Prepare payment payload
            $payload = [
                'merchant_code' => $this->merchantCode,
                'merchant_ref_id' => $referenceId,
                'amount' => (int) round($data['amount'] * 100), // Cents
                'currency' => $data['currency'] ?? 'USD',
                'description' => $data['description'] ?? 'Payment',
                'callback_url' => $data['callback_url'],
                'return_url' => $data['return_url'] ?? '',
                'cancel_url' => $data['cancel_url'] ?? '',
                'customer_name' => $data['customer_name'] ?? '',
                'customer_email' => $data['customer_email'] ?? '',
                'customer_phone' => $data['customer_phone'] ?? '',
                'timestamp' => now()->timestamp,
            ];

            // Generate HMAC-SHA256 signature
            $payload['signature'] = $this->generateSignature($payload);

            // Call Wing API
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->apiEndpoint}/api/v1/payment/create", $payload);

            if (!$response->successful()) {
                Log::error('Wing: API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'status' => 'failed',
                    'error' => 'Wing API error',
                ];
            }

            $result = $response->json();

            // Check Wing response
            if (isset($result['code']) && $result['code'] !== '0') {
                Log::error('Wing: Payment error', [
                    'code' => $result['code'],
                    'message' => $result['message'] ?? '',
                ]);

                return [
                    'status' => 'failed',
                    'error' => $result['message'] ?? 'Payment creation failed',
                ];
            }

            Log::info('Wing: Payment session created', [
                'payment_id' => $data['payment_id'],
                'reference_id' => $referenceId,
            ]);

            return [
                'status' => 'success',
                'transaction_id' => $result['transaction_id'] ?? $referenceId,
                'payment_url' => $result['payment_url'] ?? '',
                'gateway_response' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('Wing: Payment initiation failed', [
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
     * VERIFY PAYMENT (Wing)
     * ════════════════════════════════════════════════════════════
     */
    public function verifyPayment(array $data): array
    {
        try {
            Log::info('Wing: Verifying payment', [
                'merchant_ref_id' => $data['merchant_ref_id'] ?? $data['transaction_id'],
            ]);

            $merchantRefId = $data['merchant_ref_id'] ?? $data['transaction_id'];

            // Prepare query payload
            $payload = [
                'merchant_code' => $this->merchantCode,
                'merchant_ref_id' => $merchantRefId,
                'timestamp' => now()->timestamp,
            ];

            $payload['signature'] = $this->generateSignature($payload);

            // Call Wing API to check status
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->apiEndpoint}/api/v1/payment/query", $payload);

            if (!$response->successful()) {
                return [
                    'status' => 'failed',
                    'error' => 'Unable to verify payment',
                ];
            }

            $result = $response->json();

            // Map Wing status to our status
            $status = match($result['status_code'] ?? '2') {
                '0' => 'completed',  // Success
                '1' => 'pending',    // Pending
                '2' => 'failed',     // Failed
                '3' => 'cancelled',  // Cancelled
                default => 'unknown',
            };

            Log::info('Wing: Payment verified', [
                'merchant_ref_id' => $merchantRefId,
                'status' => $status,
            ]);

            return [
                'status' => $status,
                'transaction_id' => $result['wing_transaction_id'] ?? $merchantRefId,
                'amount' => ($result['amount'] ?? 0) / 100,
                'paid_at' => $result['paid_at'] ?? now(),
                'raw_data' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('Wing: Payment verification failed', [
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
     * PROCESS REFUND (Wing)
     * ════════════════════════════════════════════════════════════
     */
    public function processRefund(string $transactionId, float $amount): array
    {
        try {
            Log::warning('Wing: Processing refund', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
            ]);

            // Prepare refund payload
            $refundRef = 'REFUND-' . $transactionId . '-' . uniqid();

            $payload = [
                'merchant_code' => $this->merchantCode,
                'wing_transaction_id' => $transactionId,
                'refund_amount' => (int) round($amount * 100),
                'merchant_refund_id' => $refundRef,
                'reason' => 'Customer request',
                'timestamp' => now()->timestamp,
            ];

            $payload['signature'] = $this->generateSignature($payload);

            // Call Wing refund API
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->apiEndpoint}/api/v1/payment/refund", $payload);

            if (!$response->successful()) {
                Log::error('Wing: Refund API error', [
                    'status' => $response->status(),
                ]);

                return [
                    'status' => 'failed',
                    'error' => 'Refund API error',
                ];
            }

            $result = $response->json();

            if (isset($result['code']) && $result['code'] === '0') {
                Log::info('Wing: Refund processed', [
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
            ];

        } catch (\Exception $e) {
            Log::error('Wing: Refund processing failed', [
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
     * VERIFY WEBHOOK SIGNATURE (Wing)
     * Wing uses HMAC-SHA256
     * ════════════════════════════════════════════════════════════
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        try {
            // Sort payload
            ksort($payload);

            // Build string (exclude signature)
            $signString = '';
            foreach ($payload as $key => $value) {
                if ($key !== 'signature') {
                    $signString .= $key . '=' . $value . '&';
                }
            }
            $signString = rtrim($signString, '&');

            // Add secret key
            $signString .= $this->secretKey;

            // Calculate HMAC-SHA256
            $calculatedSignature = hash_hmac('sha256', $signString, $this->secretKey);

            // Compare
            $isValid = hash_equals($calculatedSignature, $signature);

            if (!$isValid) {
                Log::warning('Wing: Invalid webhook signature', [
                    'expected' => $calculatedSignature,
                    'received' => $signature,
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('Wing: Signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getName(): string
    {
        return 'Wing Money';
    }

    public function getCode(): string
    {
        return 'wing';
    }

    /**
     * Generate HMAC-SHA256 signature for Wing
     */
    protected function generateSignature(array $params): string
    {
        ksort($params);

        $signString = '';
        foreach ($params as $key => $value) {
            if ($key !== 'signature') {
                $signString .= $key . '=' . $value . '&';
            }
        }
        $signString = rtrim($signString, '&');
        $signString .= $this->secretKey;

        return hash_hmac('sha256', $signString, $this->secretKey);
    }
}