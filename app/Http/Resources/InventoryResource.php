<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'inventory_id' => $this->inventory_id,
            'branch' => [
                'branch_id' => $this->branch?->branch_id,
                'branch_name' => $this->branch?->branch_name,
                'branch_code' => $this->branch?->branch_code,
            ],
            'product' => [
                'product_id' => $this->product?->product_id,
                'product_name' => $this->product?->product_name,
                'product_code' => $this->product?->product_code,
                'image_url' => $this->product?->image_url,
            ],
            'quantity_on_hand' => $this->quantity_on_hand,
            'quantity_reserved' => $this->quantity_reserved,
            'quantity_available' => $this->quantity_available,
            'reorder_level' => $this->reorder_level,
            'reorder_quantity' => $this->reorder_quantity,
            'stock_status' => $this->stock_status,
            'is_low_stock' => $this->is_low_stock,
            'is_out_of_stock' => $this->is_out_of_stock,
            'last_updated' => $this->last_updated?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}