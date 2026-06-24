<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KHQRPaymentGateway implements PaymentGatewayInterface
{
    protected $gateway;
    protected $merchantId;
    protected $apiKey;
    protected $apiEndpoint;

    public function __construct(PaymentGateway $gateway)
    {
        $this->gateway = $gateway;
        
        // Decrypt credentials
        $credentials = json_decode(
            decrypt($gateway->api_credentials_encrypted),
            true
        );

        $this->merchantId = $credentials['merchant_id'] ?? '';
        $this->apiKey = $credentials['api_key'] ?? '';
        $this->apiEndpoint = $gateway->api_endpoint;
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GENERATE KHQR (Dynamic QR per transaction)
     * ════════════════════════════════════════════════════════════
     */
    public function initiatePayment(array $data): array
    {
        try {
            Log::info('KHQR: Initiating payment', [
                'amount' => $data['amount'],
                'payment_id' => $data['payment_id'],
            ]);

            // ═══════════════════════════════════════════════════
            // Generate KHQR QR code
            // ═══════════════════════════════════════════════════
            $qrData = $this->generateKHQRData($data);

            // ═══════════════════════════════════════════════════
            // Call KHQR API to create dynamic QR
            // ═══════════════════════════════════════════════════
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->apiEndpoint}/qr/create", [
                'merchant_id' => $this->merchantId,
                'amount' => (int) round($data['amount'] * 100), // Convert to cents
                'currency' => $data['currency'] ?? 'KHR',
                'reference_id' => $data['payment_id'],
                'description' => $data['description'] ?? 'Payment',
                'callback_url' => $data['callback_url'],
            ]);

            if (!$response->successful()) {
                throw new \Exception('KHQR API error: ' . $response->body());
            }

            $result = $response->json();

            Log::info('KHQR: QR generated successfully', [
                'payment_id' => $data['payment_id'],
                'qr_code_id' => $result['qr_code_id'] ?? null,
            ]);

            return [
                'status' => 'success',
                'transaction_id' => $result['qr_code_id'] ?? $data['payment_id'],
                'payment_url' => $result['qr_image_url'] ?? null,
                'qr_code' => $result['qr_string'] ?? null,
                'qr_image_url' => $result['qr_image_url'] ?? null,
                'gateway_response' => $result,
                'method' => 'khqr', // Static method
            ];

        } catch (\Exception $e) {
            Log::error('KHQR: Payment initiation failed', [
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
     * VERIFY PAYMENT (Check if paid via webhook or polling)
     * ════════════════════════════════════════════════════════════
     */
    public function verifyPayment(array $data): array
    {
        try {
            Log::info('KHQR: Verifying payment', [
                'qr_code_id' => $data['qr_code_id'] ?? $data['reference_id'],
            ]);

            // ═══════════════════════════════════════════════════
            // Query payment status
            // ═══════════════════════════════════════════════════
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->get("{$this->apiEndpoint}/qr/status", [
                'qr_code_id' => $data['qr_code_id'] ?? $data['reference_id'],
            ]);

            if (!$response->successful()) {
                return [
                    'status' => 'failed',
                    'error' => 'Unable to verify payment',
                ];
            }

            $result = $response->json();

            // Check payment status
            $paymentStatus = $result['status'] ?? 'pending';
            
            if ($paymentStatus === 'completed' || $paymentStatus === 'settled') {
                Log::info('KHQR: Payment verified', [
                    'qr_code_id' => $data['qr_code_id'],
                    'amount' => $result['amount'] ?? 0,
                ]);

                return [
                    'status' => 'completed',
                    'transaction_id' => $result['transaction_id'] ?? $data['reference_id'],
                    'amount' => ($result['amount'] ?? 0) / 100, // Convert back to regular
                    'paid_at' => $result['paid_at'] ?? now(),
                    'raw_data' => $result,
                ];
            }

            return [
                'status' => 'pending',
                'transaction_id' => $data['reference_id'],
                'raw_data' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('KHQR: Payment verification failed', [
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
     * Note: KHQR refunds are handled differently (bank transfer)
     * ════════════════════════════════════════════════════════════
     */
    public function processRefund(string $transactionId, float $amount): array
    {
        try {
            Log::warning('KHQR: Processing refund', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
            ]);

            // ═══════════════════════════════════════════════════
            // KHQR refunds typically require manual transfer
            // Or call refund API if available
            // ═══════════════════════════════════════════════════
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->post("{$this->apiEndpoint}/refund/initiate", [
                'transaction_id' => $transactionId,
                'amount' => (int) round($amount * 100),
                'reason' => 'Customer refund request',
            ]);

            if (!$response->successful()) {
                // Refund may need manual processing
                return [
                    'status' => 'pending',
                    'refund_id' => 'KHQR-MANUAL-' . $transactionId,
                    'note' => 'Refund requires manual bank transfer',
                ];
            }

            $result = $response->json();

            return [
                'status' => 'completed',
                'refund_id' => $result['refund_id'] ?? 'KHQR-REF-' . $transactionId,
                'gateway_response' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('KHQR: Refund processing failed', [
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
            'reference_id' => $transactionId,
        ]);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * VERIFY WEBHOOK SIGNATURE (KHQR Security)
     * ════════════════════════════════════════════════════════════
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        try {
            // ═══════════════════════════════════════════════════
            // KHQR uses HMAC-SHA256 signature
            // ═══════════════════════════════════════════════════
            
            // Sort payload keys
            ksort($payload);
            
            // Build string to sign
            $signString = '';
            foreach ($payload as $key => $value) {
                if ($key !== 'signature') {
                    $signString .= $key . '=' . $value . '&';
                }
            }
            $signString = rtrim($signString, '&');

            // Add API key
            $signString .= $this->apiKey;

            // Calculate signature
            $calculatedSignature = hash_hmac('sha256', $signString, $this->apiKey);

            // Compare using timing-safe comparison
            $isValid = hash_equals($calculatedSignature, $signature);

            if (!$isValid) {
                Log::warning('KHQR: Invalid webhook signature', [
                    'expected' => $calculatedSignature,
                    'received' => $signature,
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('KHQR: Signature verification failed', [
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
        return 'KHQR Payment';
    }

    public function getCode(): string
    {
        return 'khqr';
    }

    /**
     * Generate KHQR data string
     * Format: Standard KHQR format
     */
    protected function generateKHQRData(array $data): string
    {
        // KHQR format: simplified version
        // In production, use official NBC KHQR encoding library
        
        $qrData = [
            'merchant_id' => $this->merchantId,
            'amount' => (int) round($data['amount'] * 100),
            'currency' => $data['currency'] ?? 'KHR',
            'reference_id' => $data['payment_id'],
            'timestamp' => time(),
        ];

        return json_encode($qrData);
    }
}