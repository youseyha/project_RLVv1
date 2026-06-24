<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class DailyReportCollection extends ResourceCollection
{
    /**
     * DAILY REPORT COLLECTION
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => $this->getMeta(),
            'links' => $this->getLinks(),
            'summary' => $this->getSummary(),
        ];
    }

    /**
     * Get metadata
     */
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

    /**
     * Get pagination links
     */
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

    /**
     * Get summary statistics
     */
    protected function getSummary(): array
    {
        return [
            'total_sales' => $this->collection->sum('total_sales'),
            'total_transactions' => $this->collection->sum('transaction_count'),
            'average_transaction' => $this->collection->avg('average_transaction'),
            'total_tax' => $this->collection->sum('total_tax'),
            'total_discount' => $this->collection->sum('total_discount'),
            'total_customers' => $this->collection->sum('customer_count'),
        ];
    }
}