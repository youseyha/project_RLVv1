<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Product;
use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionService
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Process a new transaction
     */
    public function processTransaction(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            // Check product in branch inventory
            foreach ($data['items'] as $item) { 
                $inventory = Inventory::where('branch_id', $data['branch_id'])
                    ->where('product_id', $item['product_id'])
                    ->first();

                if (!$inventory) {
                    throw new \Exception("ផលិតផលនេះ មិនមាននៅសាខានេះទេ!");
                }
                // Check available stock
                $quantityAvailable = $inventory->quantity_on_hand - $inventory->quantity_reserved;
                if ($quantityAvailable < $item['quantity']) {
                    throw new \Exception("ស្តុកមិនគ្រប់គ្រាន់សម្រាប់ផលិតផល {$inventory?->product->product_name} ទេ! ស្តុកបច្ចុប្បន្ន: {$quantityAvailable}");
                }
            }
            // Calculate totals
            $calculations = $this->calculateTotal($data['items'], $data['discount_amount'] ?? 0);

            // Create transaction
            $transaction = Transaction::create([
                'transaction_number' => $this->generateTransactionNumber(),
                'branch_id' => $data['branch_id'],
                'terminal_id' => $data['terminal_id'] ?? null,
                'user_id' => $data['user_id'],
                'transaction_date' => now(),
                'subtotal' => $calculations['subtotal'],
                'tax_amount' => $calculations['tax_amount'],
                'discount_amount' => $calculations['discount_amount'],
                'total_amount' => $calculations['total_amount'],
                'status' => $data['status'] ?? 'completed',
                'notes' => $data['notes'] ?? null,
            ]);

            // Create transaction items & deduct inventory
            foreach ($calculations['items'] as $itemData) {
                TransactionItem::create([
                    'transaction_id' => $transaction->transaction_id,
                    'product_id' => $itemData['product_id'],
                    'product_name' => $itemData['product_name'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'discount' => $itemData['discount'],
                    'line_total' => $itemData['line_total'],
                ]);

                // Deduct inventory
                $this->deductInventory(
                    $data['branch_id'],
                    $itemData['product_id'],
                    $itemData['quantity'],
                    $transaction->transaction_id,
                    $data['user_id']
                );
            }

            return $transaction->load(['items', 'branch', 'user']);
        });
    }

    /**
     * Calculate transaction totals
     */
    public function calculateTotal(array $items, float $globalDiscountAmount = 0): array
    {
        $subtotal = 0;
        $totalItemDiscount = 0;
        $totalTax = 0;
        $processedItems = [];

        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);
            
            $quantity = $item['quantity'];
            $unitPrice = $product->base_price;
            
            // Item subtotal (before discount)
            $itemSubtotal = $unitPrice * $quantity;
            
            // Item discount (តម្លៃដុល្លារ មិនមែន percentage)
            $itemDiscount = $item['discount'] ?? 0;
            
            // Line total (after item discount, before tax)
            $afterDiscount = $itemSubtotal - $itemDiscount;
            
            // Calculate tax
            $itemTax = $this->calculateTax($afterDiscount, $product->tax_rate ?? 0);
            
            // Line total with tax
            $lineTotal = $afterDiscount + $itemTax;
            
            $processedItems[] = [
                'product_id' => $product->product_id,
                'product_name' => $product->product_name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $itemDiscount,
                'line_total' => $lineTotal,
            ];
            
            $subtotal += $itemSubtotal;
            $totalItemDiscount += $itemDiscount;
            $totalTax += $itemTax;
        }

        // Total discount (item discount + global discount)
        $totalDiscount = $totalItemDiscount + $globalDiscountAmount;
        
        // Final total
        $totalAmount = $subtotal - $totalDiscount + $totalTax;

        return [
            'items' => $processedItems,
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($totalDiscount, 2),
            'tax_amount' => round($totalTax, 2),
            'total_amount' => round($totalAmount, 2),
        ];
    }

    /**
     * Apply discount (percentage to amount)
     */
    public function applyDiscount(float $amount, float $discountPercentage): float
    {
        if ($discountPercentage <= 0 || $discountPercentage > 100) {
            return 0;
        }

        return round(($amount * $discountPercentage) / 100, 2);
    }

    /**
     * Calculate tax
     */
    public function calculateTax(float $amount, float $taxRate): float
    {
        if ($taxRate <= 0) {
            return 0;
        }

        return round(($amount * $taxRate) / 100, 2);
    }

    /**
     * Deduct inventory
     */
    public function deductInventory(
        string $branchId,
        string $productId,
        float $quantity,
        string $transactionId,
        string $userId
    ): void {
        $inventory = Inventory::where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->firstOrFail();

        $this->inventoryService->adjustStock(
            inventoryId: $inventory->inventory_id,
            quantity: $quantity,
            movementType: 'sale',
            userId: $userId,
            referenceNumber: $transactionId,
            notes: "Sale transaction"
        );
    }

    /**
     * Cancel transaction
     */
    public function cancelTransaction(
        string $transactionId,
        string $userId,
        ?string $reason = null
    ): Transaction {
        return DB::transaction(function () use ($transactionId, $userId, $reason) {
            $transaction = Transaction::with('items')->findOrFail($transactionId);

            if (!$transaction->is_cancellable) {
                throw new \Exception('មិនអាចលុបចោលប្រតិបត្តិការនេះបានទេ!');
            }

            // Restore inventory
            foreach ($transaction->items as $item) {
                $this->restoreInventory(
                    $transaction->branch_id,
                    $item->product_id,
                    $item->quantity,
                    $transactionId,
                    $userId
                );
            }

            // Update status
            $transaction->update([
                'status' => 'cancelled',
                'notes' => $transaction->notes . "\n\nCancelled: " . ($reason ?? 'No reason provided'),
            ]);

            return $transaction->fresh();
        });
    }

    /**
     * Process refund
     */
    public function processRefund(
        string $transactionId,
        array $items,
        string $userId,
        ?string $reason = null
    ): Transaction {
        return DB::transaction(function () use ($transactionId, $items, $userId, $reason) {
            $originalTransaction = Transaction::with(['items', 'branch'])->findOrFail($transactionId);

            if (!$originalTransaction->is_refundable) {
                throw new \Exception('មិនអាចសងប្រាក់វិញបានទេ!');
            }

            // Calculate refund amounts
            $refundSubtotal = 0;
            $refundItems = [];

            foreach ($items as $item) {
                $originalItem = $originalTransaction->items()
                    ->where('product_id', $item['product_id'])
                    ->firstOrFail();

                if ($item['quantity'] > $originalItem->quantity) {
                    throw new \Exception('ចំនួនសងវិញច្រើនជាងចំនួនដើម!');
                }

                $refundLineTotal = ($originalItem->unit_price * $item['quantity']) - 
                                  (($originalItem->discount / $originalItem->quantity) * $item['quantity']);

                $refundItems[] = [
                    'product_id' => $item['product_id'],
                    'product_name' => $originalItem->product_name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $originalItem->unit_price,
                    'discount' => ($originalItem->discount / $originalItem->quantity) * $item['quantity'],
                    'line_total' => $refundLineTotal,
                ];

                $refundSubtotal += $refundLineTotal;
            }

            // Create refund transaction
            $refund = Transaction::create([
                'transaction_number' => $this->generateTransactionNumber(),
                'branch_id' => $originalTransaction->branch_id,
                'terminal_id' => $originalTransaction->terminal_id,
                'user_id' => $userId,
                'transaction_date' => now(),
                'subtotal' => $refundSubtotal,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $refundSubtotal,
                'status' => 'refunded',
                'notes' => "Refund for: {$originalTransaction->transaction_number}. Reason: {$reason}",
            ]);

            // Create refund items
            foreach ($refundItems as $itemData) {
                TransactionItem::create([
                    'transaction_id' => $refund->transaction_id,
                    'product_id' => $itemData['product_id'],
                    'product_name' => $itemData['product_name'],
                    'quantity' => $itemData['quantity'], 
                    'unit_price' => $itemData['unit_price'],
                    'discount' => $itemData['discount'],
                    'line_total' => $itemData['line_total'], 
                ]);

                // Restore inventory
                $this->restoreInventory(
                    $originalTransaction->branch_id,
                    $itemData['product_id'],
                    $itemData['quantity'],
                    $refund->transaction_id,
                    $userId
                );
            }

            return $refund->load(['items', 'branch', 'user']);
        });
    }

    /**
     * Restore inventory
     */
    protected function restoreInventory(
        string $branchId,
        string $productId,
        float $quantity,
        string $referenceId,
        string $userId
    ): void {
        $inventory = Inventory::where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->firstOrFail();

        $this->inventoryService->adjustStock(
            inventoryId: $inventory->inventory_id,
            quantity: $quantity,
            movementType: 'return_from_customer',
            userId: $userId,
            referenceNumber: $referenceId,
            notes: "Refund/Cancel transaction"
        );
    }

    /**
     * Generate transaction number
     */
    protected function generateTransactionNumber(): string
    {
        $prefix = 'TRX';
        $date = date('Ymd');
        $random = strtoupper(Str::random(6));
        
        return "{$prefix}-{$date}-{$random}";
    }
}