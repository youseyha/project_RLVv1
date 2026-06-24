<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockMovementCollection;
use App\Http\Resources\StockMovementResource;
use App\Models\StockMovement;
use App\Models\Inventory;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    /**
     * API 1: MOVEMENT HISTORY - ប្រវត្តិចលនាស្តុក
     * 
     * GET /api/v1/stock-movements
     * 
     * Query Parameters:
     * - branch_id: Filter by branch
     * - product_id: Filter by product
     * - movement_type: Filter by type
     * - date_from: Start date
     * - date_to: End date
     * - per_page: Items per page (default: 20)
     * 
     * Use Case: មើលប្រវត្តិចលនាស្តុកទាំងអស់
     */
    public function index(Request $request)
    {
        $tenant = app('tenant');

        $query = StockMovement::with(['branch', 'product', 'user', 'inventory'])
            ->whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            });

        // Filter by branch
        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filter by product
        if ($request->product_id) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by movement type
        if ($request->movement_type) {
            $query->where('movement_type', $request->movement_type);
        }

        // Filter by reference number
        if ($request->reference_number) {
            $query->where('reference_number', $request->reference_number);
        }

        // Filter by date range
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Order and paginate
        $movements = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return new StockMovementCollection($movements);
    }

    /**
     * API 2: GET SINGLE MOVEMENT
     * 
     * GET /api/v1/stock-movements/{id}
     */
    public function show(string $id)
    {
        $tenant = app('tenant');

        $movement = StockMovement::whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->with(['branch', 'product', 'user', 'inventory'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new StockMovementResource($movement),
        ]);
    }

    /**
     * API 3: PRODUCT MOVEMENT HISTORY
     * 
     * GET /api/v1/stock-movements/product/{productId}
     * 
     * មើលប្រវត្តិចលនាស្តុករបស់ផលិតផលមួយ
     */
    public function byProduct(string $productId, Request $request)
    {
        $tenant = app('tenant');

        $query = StockMovement::with(['branch', 'product', 'user'])
            ->whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->where('product_id', $productId);

        // Optional branch filter
        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        // Optional date range
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $movements = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return new StockMovementCollection($movements);
    }

    /**
     * API 4: BRANCH MOVEMENT SUMMARY
     * 
     * GET /api/v1/stock-movements/branch/{branchId}/summary
     * 
     * សង្ខេបចលនាស្តុករបស់សាខាមួយ
     */
    public function branchSummary(string $branchId, Request $request)
    {
        $dateFrom = $request->date_from ?? now()->startOfMonth();
        $dateTo = $request->date_to ?? now()->endOfMonth();

        $query = StockMovement::where('branch_id', $branchId)
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        // Count by type
        $summary = [
            'total_movements' => (clone $query)->count(),
            'by_type' => [
                'purchase' => (clone $query)->where('movement_type', 'purchase')->count(),
                'sale' => (clone $query)->where('movement_type', 'sale')->count(),
                'adjustment_in' => (clone $query)->where('movement_type', 'adjustment_in')->count(),
                'adjustment_out' => (clone $query)->where('movement_type', 'adjustment_out')->count(),
                'transfer_in' => (clone $query)->where('movement_type', 'transfer_in')->count(),
                'transfer_out' => (clone $query)->where('movement_type', 'transfer_out')->count(),
                'damage' => (clone $query)->where('movement_type', 'damage')->count(),
                'return_from_customer' => (clone $query)->where('movement_type', 'return_from_customer')->count(),
                'return_to_supplier' => (clone $query)->where('movement_type', 'return_to_supplier')->count(),
            ],
            'total_quantity_in' => (clone $query)->where('quantity', '>', 0)->sum('quantity'),
            'total_quantity_out' => abs((clone $query)->where('quantity', '<', 0)->sum('quantity')),
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * API 5: RECENT MOVEMENTS
     * 
     * GET /api/v1/stock-movements/recent
     * 
     * ចលនាស្តុកថ្មីៗ (default: 30 days)
     */
    public function recent(Request $request)
    {
        $tenant = app('tenant');
        $days = $request->days ?? 30;

        $movements = StockMovement::with(['branch', 'product', 'user'])
            ->whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->limit($request->limit ?? 50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => StockMovementResource::collection($movements),
            'count' => $movements->count(),
        ]);
    }
}