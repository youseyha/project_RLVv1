<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockAdjustmentService
{
    /**
     * ════════════════════════════════════════════════════════════
     * ADJUST STOCK - កែតម្រូវស្តុក
     * ════════════════════════════════════════════════════════════
     * 
     * គោលបំណង: កែតម្រូវចំនួនស្តុកក្នុងករណី:
     * - រកឃើញខុស (count mismatch)
     * - ខូច (damage)
     * - បាត់បង់ (loss)
     * - ត្រឡប់មកវិញ (return)
     * 
     * Movement Types:
     * - adjustment: កែតម្រូវទូទៅ
     * - damage: ខូច
     * - return: ត្រឡប់មកវិញ
     * 
     * @param array $data
     * @return StockMovement
     * @throws \Exception
     */
    public function adjustStock(array $data): StockMovement
    {
        return DB::transaction(function () use ($data) {
            // រកស្តុក
            $inventory = Inventory::where('branch_id', $data['branch_id'])
                ->where('product_id', $data['product_id'])
                ->lockForUpdate()
                ->firstOrFail();
            // រក្សាទុកចំនួនមុន
            $quantityBefore = $inventory->quantity_on_hand;

            // ពិនិត្យចំនួនថ្មី
            $newQuantity = $inventory->quantity_on_hand + $data['adjustment_quantity'];
             if (in_array($data['movement_type'], [StockMovement::TYPE_PURCHASE, StockMovement::TYPE_TRANSFER_IN, StockMovement::TYPE_RETURN_FROM_CUSTOMER, StockMovement::TYPE_ADJUSTMENT_IN])) {
                // Increase stock
                $inventory->quantity_on_hand += $data['adjustment_quantity'];
            } else {
                // Decrease stock
                $inventory->quantity_on_hand -= $data['adjustment_quantity'];

                // Prevent negative stock
                if ($inventory->quantity_on_hand < 0) {
                    throw new \Exception('ស្តុកមិនគ្រប់គ្រាន់! មាន: ' . $quantityBefore . ', ត្រូវការ: ' . $data['adjustment_quantity']);
                }
            }

            if ($newQuantity < 0) {
                throw new \Exception(
                    'ចំនួនស្តុកមិនអាចតិចជាង 0 បានទេ! ' .
                    'ចំនួនបច្ចុប្បន្ន: ' . $inventory->quantity_on_hand . ', ' .
                    'កែតម្រូវ: ' . $data['adjustment_quantity']
                );
            }
            
            // កែប្រែចំនួនស្តុក
            $inventory->last_updated = now();
            $inventory->save();

            // បង្កើត Stock Movement
            $movement = StockMovement::create([
                'inventory_id' => $inventory->inventory_id,
                'product_id' => $data['product_id'],
                'branch_id' => $data['branch_id'],
                'user_id' => $data['user_id'],
                'movement_type' => $data['movement_type'] ?? StockMovement::TYPE_ADJUSTMENT_IN,
                'quantity' => $data['adjustment_quantity'], // +/- based on adjustment
                'quantity_before' => $quantityBefore,
                'quantity_after' => $inventory->quantity_on_hand,
                'reference_number' => $data['reference_number'] ?? $this->generateAdjustmentNumber(),
                'notes' => json_encode([
                    'reason' => $data['reason'] ?? null,
                    'adjustment_type' => $data['adjustment_quantity'] > 0 ? 'increase' : 'decrease',
                    'user_notes' => $data['notes'] ?? null,
                    'adjusted_at' => now()->toDateTimeString(),
                ]),
            ]);

            // ពិនិត្យស្តុកតិច (Low Stock Alert)
            if ($inventory->quantity_on_hand <= $inventory->reorder_level) {
                event(new \App\Events\LowStockDetected($inventory));
            }

            return $movement->load(['inventory', 'product', 'branch', 'user']);
        });
    }

    /**
     * ════════════════════════════════════════════════════════════
     * BULK ADJUSTMENT - កែតម្រូវច្រើន
     * ════════════════════════════════════════════════════════════
     * 
     * កែតម្រូវផលិតផលច្រើននៅពេលតែមួយ
     * ប្រើក្នុងករណី: ពិនិត្យស្តុកប្រចាំខែ (monthly stock count)
     */
    public function bulkAdjustment(array $adjustments, string $userId, ?string $reason = null): array
    {
        $results = [];
        $errors = [];

        DB::transaction(function () use ($adjustments, $userId, $reason, &$results, &$errors) {
            $referenceNumber = $this->generateAdjustmentNumber();

            foreach ($adjustments as $index => $adjustment) {
                try {
                    $movement = $this->adjustStock([
                        'branch_id' => $adjustment['branch_id'],
                        'product_id' => $adjustment['product_id'],
                        'adjustment_quantity' => $adjustment['adjustment_quantity'],
                        'movement_type' => $adjustment['movement_type'] ?? StockMovement::TYPE_ADJUSTMENT_IN,
                        'user_id' => $userId,
                        'reference_number' => $referenceNumber,
                        'reason' => $reason,
                        'notes' => $adjustment['notes'] ?? null,
                    ]);

                    $results[] = $movement;

                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'product_id' => $adjustment['product_id'] ?? null,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // បើមានកំហុស rollback
            if (!empty($errors)) {
                throw new \Exception('មានកំហុសក្នុងការកែតម្រូវ: ' . count($errors) . ' items failed');
            }
        });

        return [
            'reference_number' => $referenceNumber ?? null,
            'success_count' => count($results),
            'failed_count' => count($errors),
            'movements' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * ════════════════════════════════════════════════════════════
     * DAMAGE STOCK - កត់ត្រាស្តុកខូច
     * ════════════════════════════════════════════════════════════
     */
    public function damageStock(string $branchId, string $productId, float $quantity, string $userId, ?string $reason = null): StockMovement
    {
        return $this->adjustStock([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'adjustment_quantity' => $quantity,
            'movement_type' => StockMovement::TYPE_DAMAGE,
            'user_id' => $userId,
            'reason' => $reason ?? 'Damaged goods',
        ]);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * RETURN STOCK - ត្រឡប់ស្តុកមកវិញ
     * ════════════════════════════════════════════════════════════
     */
    public function returnStock(string $branchId, string $productId, float $quantity, string $userId, ?string $reason = null): StockMovement
    {
        return $this->adjustStock([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'adjustment_quantity' => abs($quantity), // Always positive
            'movement_type' => StockMovement::TYPE_RETURN_FROM_CUSTOMER,
            'user_id' => $userId,
            'reason' => $reason ?? 'Stock return',
        ]);
    }

    /**
     * Generate adjustment number
     */
    protected function generateAdjustmentNumber(): string
    {
        $prefix = 'ADJ';
        $date = date('Ymd');
        $random = strtoupper(Str::random(6));
        
        return "{$prefix}-{$date}-{$random}";
    }
}