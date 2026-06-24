<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class StockMovementCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => $this->getMeta(),
            'links' => $this->getLinks(),
            'summary' => $this->getSummary(),
        ];
    }

    protected function getMeta(): array
    {
        if ($this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            return [
                'total' => $this->resource->total(),
                'count' => $this->resource->count(),
                'per_page' => $this->resource->perPage(),
                'current_page' => $this->resource->currentPage(),
                'total_pages' => $this->resource->lastPage(),
            ];
        }
        
        return [
            'total' => $this->collection->count(),
            'count' => $this->collection->count(),
        ];
    }

    protected function getLinks(): array
    {
        if ($this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            return [
                'first' => $this->resource->url(1),
                'last' => $this->resource->url($this->resource->lastPage()),
                'prev' => $this->resource->previousPageUrl(),
                'next' => $this->resource->nextPageUrl(),
            ];
        }
        
        return [];
    }

    protected function getSummary(): array
    {
        return [
            'total_movements' => $this->collection->count(),
            'total_quantity_in' => $this->collection->where('quantity', '>', 0)->sum('quantity'),
            'total_quantity_out' => abs($this->collection->where('quantity', '<', 0)->sum('quantity')),
            'by_type' => $this->collection->groupBy('movement_type')->map->count(),
        ];
    }
}