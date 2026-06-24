<?php

namespace App\Traits;

trait PaginationTrait
{
    /**
     * Get pagination meta data
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'total' => $paginator->total(),
            'count' => $paginator->count(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'total_pages' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * Get pagination links
     */
    protected function paginationLinks($paginator): array
    {
        return [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];
    }

    /**
     * Format paginated response
     */
    protected function paginatedResponse($paginator, $message = 'Data retrieved successfully')
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => $this->paginationMeta($paginator),
            'links' => $this->paginationLinks($paginator),
        ]);
    }
}