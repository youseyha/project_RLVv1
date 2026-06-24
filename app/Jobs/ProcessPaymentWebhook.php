<?php

namespace App\Jobs;

use App\Models\PaymentGateway;
use App\Services\PaymentService;
use App\Services\Gateways\ABAPaymentGateway;
use App\Services\Gateways\WingPaymentGateway;
use App\Services\Gateways\KHQRPaymentGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;
    public $backoff = [60, 300, 600];
    public $maxExceptions = 1;

    protected $gatewayCode;
    protected $webhookData;

    public function __construct(string $gatewayCode, array $webhookData)
    {
        $this->gatewayCode = $gatewayCode;
        $this->webhookData = $webhookData;
    }

    /**
     * ════════════════════════════════════════════════════════════
     * EXECUTE JOB
     * ════════════════════════════════════════════════════════════
     */
    public function handle(PaymentService $paymentService): void
    {
        try {
            Log::info('Webhook job executing', [
                'gateway' => $this->gatewayCode,
                'attempt' => $this->attempts(),
            ]);

    
            // Step 1: Verify webhook signature
    
            $this->verifyWebhookSignature();

    
            // Step 2: Process webhook via service
    
            $result = $paymentService->processWebhookCallback(
                $this->gatewayCode,
                $this->webhookData
            );

            Log::info('Webhook job completed successfully', [
                'gateway' => $this->gatewayCode,
                'payment_id' => $result['payment']->payment_id ?? null,
                'status' => $result['status'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Webhook job error', [
                'gateway' => $this->gatewayCode,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Retry with exponential backoff
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 600);
            } else {
                Log::critical('Webhook job failed permanently', [
                    'gateway' => $this->gatewayCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * VERIFY WEBHOOK SIGNATURE
     * Security validation before processing
     * ════════════════════════════════════════════════════════════
     */
    protected function verifyWebhookSignature(): void
    {
        // Get gateway instance
        $gateway = PaymentGateway::where('gateway_code', $this->gatewayCode)
            ->where('status', 'active')
            ->firstOrFail();

        $gatewayInstance = match($this->gatewayCode) {
            'aba' => new ABAPaymentGateway($gateway),
            'wing' => new WingPaymentGateway($gateway),
            'khqr' => new KHQRPaymentGateway($gateway),
            default => throw new \Exception("Unknown gateway: {$this->gatewayCode}"),
        };

        // Get signature from webhook data
        $signature = match($this->gatewayCode) {
            'aba' => $this->webhookData['hash'] ?? '',
            'wing' => $this->webhookData['signature'] ?? '',
            'khqr' => $this->webhookData['signature'] ?? '',
            default => '',
        };

        // Verify signature
        if (!$gatewayInstance->verifyWebhookSignature(
            $this->webhookData,
            $signature
        )) {
            Log::warning('Webhook signature verification failed', [
                'gateway' => $this->gatewayCode,
            ]);

            throw new \Exception('Invalid webhook signature');
        }

        Log::info('Webhook signature verified', [
            'gateway' => $this->gatewayCode,
        ]);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * HANDLE JOB FAILURE
     * ════════════════════════════════════════════════════════════
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Webhook job failed permanently', [
            'gateway' => $this->gatewayCode,
            'error' => $exception->getMessage(),
            'webhook_data' => $this->webhookData,
        ]);

        // TODO: Send alert to admin
        // TODO: Create support ticket
    }
}