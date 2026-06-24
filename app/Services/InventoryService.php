<?php

namespace App\Services;

use App\Events\LowStockDetected;
use App\Models\Inventory;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Mockery\Undefined;

class InventoryService
{
    /**
     * ═══════════════════════════════════════════════════════════
     * STOCK LEVEL METHODS
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * Get inventory by branch
     */
    public function getInventoryByBranch(string $branchId, array $filters = [])
    {
        $query = Inventory::where('branch_id', $branchId)
            ->with(['product', 'branch']);

        // Filter by stock status
        if (isset($filters['stock_status'])) {
            switch ($filters['stock_status']) {
                case 'low_stock':
                    $query->lowStock();
                    break;
                case 'out_of_stock':
                    $query->outOfStock();
                    break;
                case 'in_stock':
                    $query->where('quantity_on_hand', '>', 0);
                    break;
            }
        }

        // Search by product name
        if (isset($filters['search'])) {
            $query->whereHas('product', function ($q) use ($filters) {
                $q->where('product_name', 'like', "%{$filters['search']}%");
            });
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Get stock value by branch
     */
    public function getStockValueByBranch(string $branchId): float
    {
        return Inventory::where('branch_id', $branchId)
            ->join('products', 'inventories.product_id', '=', 'products.product_id')
            ->selectRaw('SUM(inventories.quantity_on_hand * products.cost_price) as total_value')
            ->value('total_value') ?? 0;
    }
    /**
     * ═══════════════════════════════════════════════════════════
     * STOCK RECEIPT METHODS (Purchase/Restock)
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * Receive stock from supplier (Purchase)
     */
    public function receiveStock(
        string $branchId,
        array $items,
        ?string $userId = null,
        ?string $supplierName = null,
        ?string $invoiceNumber = null,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use (
            $branchId,
            $items,
            $userId,
            $supplierName,
            $invoiceNumber,
            $notes
        ) {
            $referenceNumber = 'PO-' . time();
            $receivedItems = [];

            foreach ($items as $item) {
                // Find or create inventory
                $inventory = Inventory::firstOrCreate(
                    [
                        'branch_id' => $branchId,
                        'product_id' => $item['product_id'],
                    ],
                    [
                        'quantity_on_hand' => 0,
                        'quantity_reserved' => 0,
                        'reorder_level' => $item['reorder_level'] ?? 10,
                        'reorder_quantity' => $item['reorder_quantity'] ?? 50,
                    ]
                );

                $quantityBefore = $inventory->quantity_on_hand;

                // Add stock
                $inventory->quantity_on_hand += $item['quantity'];
                $inventory->last_updated = now();
                $inventory->save();

                // Record movement
                StockMovement::create([
                    'inventory_id' => $inventory->inventory_id,
                    'product_id' => $item['product_id'],
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                    'movement_type' => 'purchase',
                    'quantity' => $item['quantity'],
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $inventory->quantity_on_hand,
                    'reference_number' => $referenceNumber,
                    'notes' => $notes . " | Supplier: {$supplierName} | Invoice: {$invoiceNumber}",
                ]);

                $receivedItems[] = $inventory->fresh(['product', 'branch']);
            }

            return [
                'items' => $receivedItems,
                'reference_number' => $referenceNumber,
                'supplier' => $supplierName,
                'invoice_number' => $invoiceNumber,
            ];
        });
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * ADJUSTMENT METHODS
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * Adjust stock (manual correction)
     */
    public function adjustStock(
        string $inventoryId,
        float $quantity,
        string $movementType,
        ?string $userId = null,
        ?string $referenceNumber = null,
        ?string $notes = null
    ): Inventory {
        return DB::transaction(function () use (
            $inventoryId,
            $quantity,
            $movementType,
            $userId,
            $referenceNumber,
            $notes
        ) {
            $inventory = Inventory::lockForUpdate()->findOrFail($inventoryId);
            $quantityBefore = $inventory->quantity_on_hand;

            // Update quantity based on movement type
            if (in_array($movementType, ['purchase', 'transfer_in', 'return_from_customer','adjustment_in'])) {
                // Increase stock
                $inventory->quantity_on_hand += $quantity;
            } else {
                // Decrease stock
                $inventory->quantity_on_hand -= $quantity;
                
                // Prevent negative stock
                if ($inventory->quantity_on_hand < 0) {
                    throw new \Exception('ស្តុកមិនគ្រប់គ្រាន់! មាន: ' . $quantityBefore . ', ត្រូវការ: ' . $quantity);
                }
            }

            $inventory->last_updated = now();
            $inventory->save();

            // Record movement
            StockMovement::create([
                'inventory_id' => $inventory->inventory_id,
                'product_id' => $inventory->product_id,
                'branch_id' => $inventory->branch_id,
                'user_id' => $userId,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $inventory->quantity_on_hand,
                'reference_number' => $referenceNumber ?? 'ADJ-' . time(),
                'notes' => $notes,
            ]);

            // Check for low stock and fire event
            $this->checkLowStock($inventory);

            return $inventory->fresh(['product', 'branch']);
        });
    }

    /**
     * Bulk adjustment
     */
    public function bulkAdjustment(array $adjustments, ?string $userId = null): array
    {
        return DB::transaction(function () use ($adjustments, $userId) {
            $results = [];
            $referenceNumber = 'BULK-ADJ-' . time();

            foreach ($adjustments as $adjustment) {
                $inventory = $this->adjustStock(
                    inventoryId: $adjustment['inventory_id'],
                    quantity: $adjustment['quantity'],
                    movementType: $adjustment['movement_type'],
                    userId: $userId,
                    referenceNumber: $referenceNumber,
                    notes: $adjustment['notes'] ?? null
                );

                $results[] = $inventory;
            }

            return [
                'items' => $results,
                'reference_number' => $referenceNumber,
            ];
        });
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * ALERT METHODS
     * ═══════════════════════════════════════════════════════════
     */
    /**
     * យកស្តុកទាប
     */
    public function getLowStockItems(?string $branchId = null)
    {
        $query = Inventory::with(['product', 'branch'])->lowStock();

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get();
    }
    /**
     * Get out of stock items
     */
    public function getOutOfStockItems(?string $branchId = null)
    {
        $query = Inventory::with(['product', 'branch'])
            ->outOfStock();

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get();
    }
    /**
     * Get reorder suggestions
     */
    public function getReorderSuggestions(?string $branchId = null): array
    {
        $query = Inventory::with(['product', 'branch'])
                ->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_level');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $lowStockItems = $query->get();

        $suggestions = [];
        foreach ($lowStockItems as $inventory) {
            $suggestions[] = [
                'inventory_id' => $inventory->inventory_id,
                'product' => [
                    'product_id' => $inventory->product->product_id,
                    'product_name' => $inventory->product->product_name,
                    'product_code' => $inventory->product->product_code,
                ],
                'branch' => [
                    'branch_id' => $inventory->branch->branch_id,
                    'branch_name' => $inventory->branch->branch_name,
                ],
                'current_stock' => $inventory->quantity_available,
                'reorder_level' => $inventory->reorder_level,
                'suggested_order_quantity' => $inventory->reorder_quantity,
                'estimated_cost' => $inventory->reorder_quantity * ($inventory->product->cost_price ?? 0),
                'urgency' => $inventory->quantity_available <= 0 ? 'critical' : 'high',
            ];
        }

        return $suggestions;
    }
    /**
     * ═══════════════════════════════════════════════════════════
     * HELPER METHODS
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * Check low stock and fire event
     */
    protected function checkLowStock(Inventory $inventory): void
    {
        if ($inventory->is_low_stock) {
            event(new LowStockDetected($inventory));
        }
    }

    /**
     * Reserve stock (for orders)
     */
    public function reserveStock(string $inventoryId, float $quantity): Inventory
    {
        return DB::transaction(function () use ($inventoryId, $quantity) {
            $inventory = Inventory::lockForUpdate()->findOrFail($inventoryId);

            $available = $inventory->quantity_on_hand - $inventory->quantity_reserved;

            if ($available < $quantity) {
                throw new \Exception('ស្តុកមិនគ្រប់គ្រាន់សម្រាប់ការកក់! មាន: ' . $available . ', ត្រូវការ: ' . $quantity);
            }

            $inventory->quantity_reserved += $quantity;
            $inventory->last_updated = now();
            $inventory->save();

            return $inventory;
        });
    }

    /**
     * Release reserved stock
     */
    public function releaseReservedStock(string $inventoryId, float $quantity): Inventory
    {
        return DB::transaction(function () use ($inventoryId, $quantity) {
            $inventory = Inventory::lockForUpdate()->findOrFail($inventoryId);

            $inventory->quantity_reserved = max(0, $inventory->quantity_reserved - $quantity);
            $inventory->last_updated = now();
            $inventory->save();

            return $inventory;
        });
    }

    /**
     * Transfer stock between branches
     */
    public function transferStock(
        string $fromBranchId,
        string $toBranchId,
        string $productId,
        float $quantity,
        ?string $userId = null,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use (
            $fromBranchId,
            $toBranchId,
            $productId,
            $quantity,
            $userId,
            $notes
        ) {
            // From branch inventory
            $fromInventory = Inventory::where('branch_id', $fromBranchId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            // Check availability
            $available = $fromInventory->quantity_on_hand - $fromInventory->quantity_reserved;
            if ($available < $quantity) {
                throw new \Exception('ស្តុកមិនគ្រប់គ្រាន់សម្រាប់ផ្ទេរ! មាន: ' . $available . ', ត្រូវការ: ' . $quantity);
            }

            // To branch inventory
            $toInventory = Inventory::firstOrCreate(
                [
                    'branch_id' => $toBranchId,
                    'product_id' => $productId,
                ],
                [
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                    'reorder_level' => $fromInventory->reorder_level,
                    'reorder_quantity' => $fromInventory->reorder_quantity,
                ]
            );

            $referenceNumber = 'TRF-' . time();

            // Reduce from source
            $this->adjustStock(
                $fromInventory->inventory_id,
                $quantity,
                'transfer_out',
                $userId,
                $referenceNumber,
                $notes
            );

            // Add to destination
            $this->adjustStock(
                $toInventory->inventory_id,
                $quantity,
                'transfer_in',
                $userId,
                $referenceNumber,
                $notes
            );

            return [
                'from_inventory' => $fromInventory->fresh(['product', 'branch']),
                'to_inventory' => $toInventory->fresh(['product', 'branch']),
                'reference_number' => $referenceNumber,
            ];
        });
    }
}