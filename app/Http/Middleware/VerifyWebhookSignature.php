<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\PaymentGateway;
use App\Services\Gateways\ABAPaymentGateway;
use App\Services\Gateways\WingPaymentGateway;
use Illuminate\Support\Facades\Log;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $gatewayCode): mixed
    {
        try {
            // Get gateway
            $gateway = PaymentGateway::where('gateway_code', $gatewayCode)
                ->where('status', 'active')
                ->firstOrFail();

            // Get gateway instance
            $gatewayInstance = match($gatewayCode) {
                'aba' => new ABAPaymentGateway($gateway),
                'wing' => new WingPaymentGateway($gateway),
                default => throw new \Exception("Unsupported gateway: {$gatewayCode}"),
            };

            // Get signature from request
            $signature = match($gatewayCode) {
                'aba' => $request->input('hash'),
                'wing' => $request->input('signature'),
                default => null,
            };

            if (!$signature) {
                Log::error('Webhook signature missing', [
                    'gateway' => $gatewayCode,
                    'data' => $request->all(),
                ]);

                return response()->json([
                    'error' => 'Signature missing',
                ], 400);
            }

            // Verify signature
            if (!$gatewayInstance->verifyWebhookSignature($request->all(), $signature)) {
                Log::error('Webhook signature verification failed', [
                    'gateway' => $gatewayCode,
                    'signature' => $signature,
                ]);

                return response()->json([
                    'error' => 'Invalid signature',
                ], 401);
            }

            Log::info('Webhook signature verified', [
                'gateway' => $gatewayCode,
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Webhook verification error', [
                'gateway' => $gatewayCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Verification failed',
            ], 500);
        }
    }
}