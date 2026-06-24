<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'movement_id' => $this->movement_id,

            'transfer_number' => $this->reference_number,

            'movement_type' => $this->movement_type,

            'branch' => [
                'branch_id' => $this->branch?->branch_id,
                'branch_name' => $this->branch?->branch_name,
                'branch_code' => $this->branch?->branch_code,
            ],

            'product' => [
                'product_id' => $this->product?->product_id,
                'product_name' => $this->product?->product_name,
                'product_code' => $this->product?->product_code,
            ],

            'quantity' => (float) $this->quantity,

            'quantity_before' => (float) $this->quantity_before,

            'quantity_after' => (float) $this->quantity_after,

            'user' => [
                'user_id' => $this->user?->user_id,
                'username' => $this->user?->username,
            ],

            'notes' => $this->notes,

            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}