<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'payment_id' => $this->payment_id,
            'invoice_id' => $this->invoice_id,
            'amount' => (float) $this->amount,
            'absolute_amount' => (float) $this->absolute_amount,
            'payment_date' => $this->payment_date->format('Y-m-d H:i:s'),
            'payment_type' => $this->payment_type,
            'status' => $this->status,
            'payment_reference' => $this->payment_reference,
            'is_refund' => $this->is_refund,
            'is_completed' => $this->is_completed,
            
            // Relationships
            'method' => $this->whenLoaded('method', function () {
                return [
                    'method_id' => $this->method->method_id,
                    'method_name' => $this->method->method_name,
                    'method_type' => $this->method->method_type,
                ];
            }),
            
            'gateway' => $this->whenLoaded('gateway', function () {
                return [
                    'gateway_id' => $this->gateway->gateway_id,
                    'gateway_name' => $this->gateway->gateway_name,
                ];
            }),
            
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}