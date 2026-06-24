<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Configuration
    |--------------------------------------------------------------------------
    */

    'default_currency' => env('PAYMENT_DEFAULT_CURRENCY', 'USD'),
    
    'timeout' => env('PAYMENT_TIMEOUT', 300),
    
    'webhook_verification' => env('PAYMENT_WEBHOOK_VERIFICATION', true),

    /*
    |--------------------------------------------------------------------------
    | Supported Gateways
    |--------------------------------------------------------------------------
    */
    
    'gateways' => [
        'aba' => [
            'name' => 'ABA PayWay',
            'enabled' => env('ABA_ENABLED', true),
            'sandbox' => env('ABA_SANDBOX', true),
        ],
        'wing' => [
            'name' => 'Wing Money',
            'enabled' => env('WING_ENABLED', true),
            'sandbox' => env('WING_SANDBOX', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency Support
    |--------------------------------------------------------------------------
    */
    
    'currencies' => [
        'USD' => [
            'symbol' => '$',
            'decimals' => 2,
            'gateways' => ['aba', 'wing'],
        ],
        'KHR' => [
            'symbol' => '៛',
            'decimals' => 0,
            'gateways' => ['wing'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Routes
    |--------------------------------------------------------------------------
    */
    
    'webhook_routes' => [
        'aba' => '/api/webhooks/aba',
        'wing' => '/api/webhooks/wing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Return URLs
    |--------------------------------------------------------------------------
    */
    
    'return_urls' => [
        'success' => env('APP_URL') . '/payments/{payment_id}/success',
        'cancel' => env('APP_URL') . '/payments/{payment_id}/cancel',
        'return' => env('APP_URL') . '/payments/{payment_id}/return',
    ],

];