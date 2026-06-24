<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Crypt;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        // ════════════════════════════════════════════════════════
        // ABA PAYWAY
        // ════════════════════════════════════════════════════════
        $aba = PaymentGateway::create([
            'gateway_name' => 'ABA PayWay',
            'gateway_code' => 'aba',
            'api_endpoint' => env('ABA_API_ENDPOINT', 'https://checkout-sandbox.payway.com.kh/api'),
            'api_credentials_encrypted' => Crypt::encryptString(json_encode([
                'merchant_id' => env('ABA_MERCHANT_ID'),
                'api_key' => env('ABA_API_KEY'),
            ])),
            'transaction_fee_percentage' => 2.50,
            'transaction_fee_fixed' => 0.50,
            'status' => 'active',
        ]);

        // ════════════════════════════════════════════════════════
        // WING MONEY
        // ════════════════════════════════════════════════════════
        $wing = PaymentGateway::create([
            'gateway_name' => 'Wing Money',
            'gateway_code' => 'wing',
            'api_endpoint' => env('WING_API_ENDPOINT', 'https://api-sandbox.wingmoney.com'),
            'api_credentials_encrypted' => Crypt::encryptString(json_encode([
                'merchant_code' => env('WING_MERCHANT_CODE'),
                'api_token' => env('WING_API_TOKEN'),
                'secret_key' => env('WING_SECRET_KEY'),
            ])),
            'transaction_fee_percentage' => 1.50,
            'transaction_fee_fixed' => 0.00,
            'status' => 'active',
        ]);

        // ════════════════════════════════════════════════════
        // KHQR PAYMENT GATEWAY
        // ════════════════════════════════════════════════════
        $khqr = PaymentGateway::create([
            'gateway_name' => 'KHQR Payment',
            'gateway_code' => 'khqr',
            'api_endpoint' => env('KHQR_API_ENDPOINT', 'https://api-sandbox.khqr.nbc.kh'),
            'api_credentials_encrypted' => Crypt::encryptString(json_encode([
                'merchant_id' => env('KHQR_MERCHANT_ID'),
                'api_key' => env('KHQR_API_KEY'),
            ])),
            'transaction_fee_percentage' => 0.0, // KHQR typically free
            'transaction_fee_fixed' => 0.0,
            'status' => 'active',
        ]);


        $this->command->info('Payment gateways seeded successfully!');
    }
}