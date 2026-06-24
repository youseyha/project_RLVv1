<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'transaction_id' => $this->transaction_id,
            'transaction_number' => $this->transaction_number,
            'transaction_date' => $this->transaction_date?->format('Y-m-d H:i:s'),
            'branch' => [
                'branch_id' => $this->branch?->branch_id,
                'branch_name' => $this->branch?->branch_name,
            ],
            'terminal_id' => $this->terminal_id,
            'cashier' => [
                'user_id' => $this->user?->user_id,
                'username' => $this->user?->username,
                'email' => $this->user?->email,
            ],
            'items' => TransactionItemResource::collection($this->whenLoaded('items')),
            'subtotal' => (float) $this->subtotal,
            'tax_amount' => (float) $this->tax_amount,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            'is_cancellable' => $this->is_cancellable,
            'is_refundable' => $this->is_refundable,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}