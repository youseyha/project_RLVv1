<?php

namespace App\Jobs;

use App\Models\Inventory;
use App\Notifications\ReorderSuggestionNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAutoReorder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Log::info('Auto-reorder job started');

        // Get low stock items
        $lowStockItems = Inventory::with(['product', 'branch'])
            ->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_level')
            ->where('quantity_on_hand', '>', 0)
            ->get();

        if ($lowStockItems->isEmpty()) {
            Log::info('No low stock items found');
            return;
        }

        Log::info("Found {$lowStockItems->count()} low stock items");

        $reorderSuggestions = [];

        foreach ($lowStockItems as $inventory) {
            $currentStock = $inventory->quantity_on_hand;
            $reorderQty = $inventory->reorder_quantity;

            // If critically low, double the order
            if ($currentStock <= 5) {
                $reorderQty = $reorderQty * 2;
            }

            $reorderSuggestions[] = [
                'branch_id' => $inventory->branch_id,
                'branch_name' => $inventory->branch->branch_name,
                'product_id' => $inventory->product_id,
                'product_name' => $inventory->product->product_name,
                'product_code' => $inventory->product->product_code,
                'current_stock' => $currentStock,
                'reorder_level' => $inventory->reorder_level,
                'suggested_quantity' => $reorderQty,
                'estimated_cost' => $reorderQty * ($inventory->product->cost_price ?? 0),
                'urgency' => $currentStock <= 5 ? 'critical' : 'high',
            ];

            $inventory->touch();
        }

        $this->notifyManagers($reorderSuggestions);

        Log::info('Auto-reorder job completed', [
            'suggestions_count' => count($reorderSuggestions)
        ]);
    }

    protected function notifyManagers(array $suggestions): void
    {
        if (empty($suggestions)) {
            return;
        }

        // Group by branch
        $groupedByBranch = collect($suggestions)->groupBy('branch_id');

        foreach ($groupedByBranch as $branchId => $items) {
            // Get managers
            $managers = \App\Models\User::whereHas('roles', function ($q) {
                    $q->whereIn('name', ['admin', 'manager']);
                })
                ->where('is_active', true)
                ->get();

            foreach ($managers as $manager) {
                $manager->notify(new ReorderSuggestionNotification([
                    'branch_id' => $branchId,
                    'items' => $items->toArray(),
                    'total_items' => $items->count(),
                    'critical_count' => $items->where('urgency', 'critical')->count(),
                ]));
            }
        }
    }
}