<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->item_id,
            'product' => [
                'product_id' => $this->product_id,
                'product_name' => $this->product_name,
            ],
            'quantity' => (float) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'discount' => (float) $this->discount,
            'line_total' => (float) $this->line_total,
        ];
    }
}