<?php

namespace App\Contracts;

interface PaymentGatewayInterface
{
    /**
     * Initialize payment and get payment URL
     *
     * @param array $data Payment data
     * @return array ['payment_url' => string, 'transaction_id' => string]
     */
    public function initiatePayment(array $data): array;

    /**
     * Verify payment callback/webhook
     *
     * @param array $data Callback data
     * @return array ['status' => string, 'transaction_id' => string, 'amount' => float]
     */
    public function verifyPayment(array $data): array;

    /**
     * Process refund
     *
     * @param string $transactionId Gateway transaction ID
     * @param float $amount Refund amount
     * @return array ['status' => string, 'refund_id' => string]
     */
    public function processRefund(string $transactionId, float $amount): array;

    /**
     * Get payment status
     *
     * @param string $transactionId Gateway transaction ID
     * @return array ['status' => string, 'amount' => float, 'paid_at' => string]
     */
    public function getPaymentStatus(string $transactionId): array;

    /**
     * Verify webhook signature
     *
     * @param array $payload Webhook payload
     * @param string $signature Signature from webhook header
     * @return bool
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool;

    /**
     * Get gateway name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get gateway code
     *
     * @return string
     */
    public function getCode(): string;
}