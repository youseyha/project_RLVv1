<?php
// app/Http/Controllers/PaymentWebhookController.php

namespace App\Http\Controllers;

use App\Jobs\ProcessPaymentWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * ════════════════════════════════════════════════════════════
     * ABA WEBHOOK - Lightweight Handler
     * ════════════════════════════════════════════════════════════
     */
    public function aba(Request $request)
    {
        Log::info('ABA webhook received', [
            'tran_id' => $request->input('tran_id'),
            'status' => $request->input('status'),
        ]);

        // Dispatch async job for processing
        ProcessPaymentWebhook::dispatch('aba', $request->all())
            ->onQueue('webhooks');

        // Return response immediately
        // (Don't wait for processing)
        return response()->json(['status' => 'received']);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * WING WEBHOOK - Lightweight Handler
     * ════════════════════════════════════════════════════════════
     */
    public function wing(Request $request)
    {
        Log::info('Wing webhook received', [
            'merchant_ref_id' => $request->input('merchant_ref_id'),
            'status_code' => $request->input('status_code'),
        ]);

        // Dispatch async job for processing
        ProcessPaymentWebhook::dispatch('wing', $request->all())
            ->onQueue('webhooks');

        // Return Wing-specific response
        return response()->json([
            'code' => '0',
            'message' => 'Success',
        ]);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * KHQR WEBHOOK - Lightweight Handler
     * ════════════════════════════════════════════════════════════
     */
    public function khqr(Request $request)
    {
        Log::info('KHQR webhook received', [
            'qr_code_id' => $request->input('qr_code_id'),
            'status' => $request->input('status'),
        ]);

        ProcessPaymentWebhook::dispatch('khqr', $request->all())
            ->onQueue('webhooks');

        return response()->json(['status' => 'received']);
    }
}