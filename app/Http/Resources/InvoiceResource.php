<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date->format('Y-m-d'),
            'due_date' => $this->due_date->format('Y-m-d'),
            
            // Amounts
            'subtotal' => (float) $this->subtotal,
            'tax_amount' => (float) $this->tax_amount,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,
            'amount_paid' => (float) $this->amount_paid,
            'amount_due' => (float) $this->amount_due,
            
            // Status
            'status' => $this->status,
            'is_overdue' => $this->is_overdue,
            'is_paid' => $this->is_paid,
            'days_overdue' => $this->days_overdue,
            
            // Relationships
            'subscription' => $this->whenLoaded('subscription'),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payment' => $this->whenLoaded('payment'),
            
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}