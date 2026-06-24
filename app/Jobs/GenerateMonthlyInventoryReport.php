<?php
// app/Jobs/GenerateMonthlyInventoryReport.php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\Tenants;
use App\Notifications\MonthlyInventoryNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateMonthlyInventoryReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ════════════════════════════════════════════════════════════
     * GENERATE MONTHLY INVENTORY REPORT JOB
     * ════════════════════════════════════════════════════════════
     * 
     * គោលបំណង: បង្កើតរបាយការណ៍ស្តុកប្រចាំខែ
     * 
     * Schedule: ថ្ងៃទី 1 នៃខែ នៅម៉ោង 08:00
     * 
     * Process:
     * ① ពិនិត្យស្តុកបច្ចុប្បន្នទាំងអស់
     * ② គណនាតម្លៃស្តុកសរុប
     * ③ រកស្តុកដែលត្រូវបញ្ជាទិញបន្ថែម
     * ④ វិភាគចលនាស្តុកខែមុន
     * ⑤ ផ្ញើជូនអ្នកគ្រប់គ្រង
     */
    public function handle(): void
    {
        Log::info('========================================');
        Log::info('Monthly Inventory Report Generation Started');
        Log::info('========================================');

        // Previous month date range
        $startDate = now()->subMonth()->startOfMonth();
        $endDate = now()->subMonth()->endOfMonth();

        Log::info("Month: {$startDate->format('F Y')}");

        // Get all active tenants
        $tenants = Tenants::where('status', 'active')->get();

        Log::info("Found {$tenants->count()} active tenants");

        foreach ($tenants as $tenant) {
            try {
                Log::info("Processing tenant: {$tenant->company_name}");

                // Generate inventory summary
                $inventorySummary = $this->generateInventorySummary($tenant->tenant_id);

                // Get stock movements for the month
                $movementSummary = $this->generateMovementSummary(
                    $tenant->tenant_id,
                    $startDate,
                    $endDate
                );

                // Get reorder recommendations
                $reorderRecommendations = $this->getReorderRecommendations($tenant->tenant_id);

                // Notify managers
                $this->notifyManagers(
                    $tenant,
                    $inventorySummary,
                    $movementSummary,
                    $reorderRecommendations,
                    $startDate
                );

                Log::info("Monthly inventory report generated for {$tenant->company_name}");

            } catch (\Exception $e) {
                Log::error("Failed to generate monthly inventory report for {$tenant->company_name}: " . $e->getMessage());
            }
        }

        Log::info('========================================');
        Log::info('Monthly Inventory Report Generation Completed');
        Log::info('========================================');
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GENERATE INVENTORY SUMMARY - សង្ខេបស្តុក
     * ════════════════════════════════════════════════════════════
     */
    protected function generateInventorySummary($tenantId): array
    {
        $inventory = Inventory::with(['product', 'branch'])
            ->whereHas('branch', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->get();

        $totalProducts = $inventory->count();
        $totalQuantity = $inventory->sum('quantity_on_hand');
        $totalReserved = $inventory->sum('quantity_reserved');
        
        // Calculate inventory value
        $totalValue = $inventory->sum(function ($item) {
            return $item->quantity_on_hand * ($item->product->cost_price ?? 0);
        });

        $totalRetailValue = $inventory->sum(function ($item) {
            return $item->quantity_on_hand * ($item->product->base_price ?? 0);
        });

        // Low stock items (តាម ERD: quantity_on_hand <= reorder_level)
        $lowStockCount = $inventory->filter(function ($item) {
            return $item->quantity_on_hand <= $item->reorder_level;
        })->count();

        // Out of stock (តាម ERD: quantity_on_hand = 0)
        $outOfStockCount = $inventory->where('quantity_on_hand', '<=', 0)->count();

        return [
            'total_products' => $totalProducts,
            'total_quantity' => $totalQuantity,
            'total_reserved' => $totalReserved,
            'total_available' => $totalQuantity - $totalReserved,
            'inventory_value' => round($totalValue, 2),
            'retail_value' => round($totalRetailValue, 2),
            'potential_profit' => round($totalRetailValue - $totalValue, 2),
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'stock_health_percentage' => $totalProducts > 0 
                ? round((($totalProducts - $lowStockCount - $outOfStockCount) / $totalProducts) * 100, 2)
                : 100,
        ];
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GENERATE MOVEMENT SUMMARY - សង្ខេបចលនាស្តុក
     * ════════════════════════════════════════════════════════════
     */
    protected function generateMovementSummary($tenantId, $startDate, $endDate): array
    {
        // តាម ERD: STOCK_MOVEMENTS table
        $movements = StockMovement::with(['product', 'branch'])
            ->whereHas('branch', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // Count by movement_type (តាម ERD enum)
        $byType = [
            'purchase' => $movements->where('movement_type', StockMovement::TYPE_PURCHASE)->count(),
            'sale' => $movements->where('movement_type', StockMovement::TYPE_SALE)->count(),
            'adjustment_in' => $movements->where('movement_type', StockMovement::TYPE_ADJUSTMENT_IN)->count(),
            'adjustment_out' => $movements->where('movement_type', StockMovement::TYPE_ADJUSTMENT_OUT)->count(),
            'transfer_in' => $movements->where('movement_type', StockMovement::TYPE_TRANSFER_IN)->count(),
            'transfer_out' => $movements->where('movement_type', StockMovement::TYPE_TRANSFER_OUT)->count(),
            'damage' => $movements->where('movement_type', StockMovement::TYPE_DAMAGE)->count(),
            'return_from_customer' => $movements->where('movement_type', StockMovement::TYPE_RETURN_FROM_CUSTOMER)->count(),
            'return_to_supplier' => $movements->where('movement_type', StockMovement::TYPE_RETURN_TO_SUPPLIER)->count(),
        ];

        // Total quantities (តាម ERD: quantity field)
        $totalIn = $movements->where('quantity', '>', 0)->sum('quantity');
        $totalOut = abs($movements->where('quantity', '<', 0)->sum('quantity'));

        return [
            'total_movements' => $movements->count(),
            'by_type' => $byType,
            'total_quantity_in' => round($totalIn, 2),
            'total_quantity_out' => round($totalOut, 2),
            'net_change' => round($totalIn - $totalOut, 2),
            'most_active_products' => $this->getMostActiveProducts($movements),
        ];
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GET REORDER RECOMMENDATIONS - អនុសាសន៍បញ្ជាទិញ
     * ════════════════════════════════════════════════════════════
     */
    protected function getReorderRecommendations($tenantId): array
    {
        // តាម ERD: INVENTORY table fields
        $lowStock = Inventory::with(['product', 'branch'])
            ->whereHas('branch', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
            ->where('quantity_on_hand', '>', 0)
            ->get()
            ->map(function ($item) {
                return [
                    'product_id' => $item->product->product_id,
                    'product_code' => $item->product->product_code,
                    'product_name' => $item->product->product_name,
                    'branch_name' => $item->branch->branch_name,
                    'current_stock' => $item->quantity_on_hand,
                    'reorder_level' => $item->reorder_level,
                    'reorder_quantity' => $item->reorder_quantity,
                    'estimated_cost' => $item->reorder_quantity * ($item->product->cost_price ?? 0),
                    'urgency' => $item->quantity_on_hand <= 5 ? 'critical' : 'high',
                ];
            })
            ->sortBy('current_stock')
            ->values()
            ->toArray();

        $totalEstimatedCost = collect($lowStock)->sum('estimated_cost');

        return [
            'items' => $lowStock,
            'total_items' => count($lowStock),
            'total_estimated_cost' => round($totalEstimatedCost, 2),
            'critical_items' => collect($lowStock)->where('urgency', 'critical')->count(),
        ];
    }

    /**
     * Get most active products
     */
    protected function getMostActiveProducts($movements): array
    {
        return $movements->groupBy('product_id')
            ->map(function ($items) {
                $product = $items->first()->product;
                return [
                    'product_name' => $product->product_name,
                    'movement_count' => $items->count(),
                    'total_in' => $items->where('quantity', '>', 0)->sum('quantity'),
                    'total_out' => abs($items->where('quantity', '<', 0)->sum('quantity')),
                ];
            })
            ->sortByDesc('movement_count')
            ->take(10)
            ->values()
            ->toArray();
    }

    /**
     * ════════════════════════════════════════════════════════════
     * NOTIFY MANAGERS
     * ════════════════════════════════════════════════════════════
     */
    protected function notifyManagers(
        Tenants $tenant,
        array $inventorySummary,
        array $movementSummary,
        array $reorderRecommendations,
        $month
    ): void {
        $managers = \App\Models\User::where('tenant_id', $tenant->tenant_id)
            ->whereIn('role', ['admin', 'manager'])
            ->where('is_active', true)
            ->get();

        foreach ($managers as $manager) {
            try {
                $manager->notify(new MonthlyInventoryNotification(
                    $inventorySummary,
                    $movementSummary,
                    $reorderRecommendations,
                    $month
                ));
            } catch (\Exception $e) {
                Log::error("Failed to send monthly inventory report to {$manager->email}: " . $e->getMessage());
            }
        }
    }
}