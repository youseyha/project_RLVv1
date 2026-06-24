<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class InventoryCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total' => $this->total(),
                'count' => $this->count(),
                'per_page' => $this->perPage(),
                'current_page' => $this->currentPage(),
                'total_pages' => $this->lastPage(),
            ],
            'links' => [
                'first' => $this->url(1),
                'last' => $this->url($this->lastPage()),
                'prev' => $this->previousPageUrl(),
                'next' => $this->nextPageUrl(),
            ],
            'summary' => [
                // Total stock value
                'total_stock_value' => $this->collection->sum(function ($inventory) {
                    return $inventory->quantity_on_hand * ($inventory->product->cost_price ?? 0);
                }),
                
                // Low stock count
                'low_stock_count' => $this->collection->filter(function ($inventory) {
                    return $inventory->is_low_stock;
                })->count(),
                
                // Out of stock count
                'out_of_stock_count' => $this->collection->filter(function ($inventory) {
                    return $inventory->is_out_of_stock;
                })->count(),
                
                // Total items
                'total_items' => $this->collection->sum('quantity_on_hand'),
                
                // Average stock level
                'average_stock' => round($this->collection->avg('quantity_on_hand'), 2),
            ],
        ];
    }
}