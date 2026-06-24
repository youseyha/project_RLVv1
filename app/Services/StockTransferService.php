<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Branches;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferService
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * ════════════════════════════════════════════════════════════
     * INITIATE TRANSFER - ចាប់ផ្តើមការផ្ទេរ
     * ════════════════════════════════════════════════════════════
     * 
     * ដំណើរការ:
     * ① ពិនិត្យមើលសាខាប្រភពមានស្តុកគ្រប់គ្រាន់
     * ② កក់ទុក (reserve) ស្តុកនៅសាខាប្រភព
     * ③ បង្កើត transfer_out movement (status: pending)
     * 
     * Notes Field Structure (JSON):
     * {
     *   "status": "pending",
     *   "to_branch_id": "uuid",
     *   "to_branch_name": "Branch Name",
     *   "transfer_quantity": 10,
     *   "user_notes": "...",
     *   "initiated_at": "2026-03-04 14:30:00"
     * }
     * 
     * នៅដំណាក់កាលនេះ:
     * - ស្តុកត្រូវបានកក់ទុក (reserved)
     * - ស្តុកមិនទាន់ត្រូវបានដកចេញ
     * - អាចលុបចោលបាន (cancellable)
     */
    public function initiateTransfer(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // ពិនិត្យមើលសាខាខុសគ្នា
            if ($data['from_branch_id'] === $data['to_branch_id']) {
                throw new \Exception('មិនអាចផ្ទេរស្តុកទៅសាខាដូចគ្នាបានទេ!');
            }

            // រកស្តុកនៅសាខាប្រភព
            $sourceInventory = Inventory::where('branch_id', $data['from_branch_id'])
                ->where('product_id', $data['product_id'])
                ->lockForUpdate() // ចាក់សោ row កុំឱ្យគេកែ
                ->first();

            if (!$sourceInventory) {
                throw new \Exception('ផលិតផលនេះមិនមានក្នុងសាខាប្រភពទេ!');
            }

            // ពិនិត្យចំនួនដែលអាចប្រើបាន
            // Available = On Hand - Reserved
            $availableQty = $sourceInventory->quantity_on_hand - 
                           $sourceInventory->quantity_reserved;

            if ($availableQty < $data['quantity']) {
                throw new \Exception(
                    "ស្តុកមិនគ្រប់គ្រាន់! មានតែ {$availableQty} អាចផ្ទេរបាន"
                );
            }

            // កក់ទុកស្តុក (Increase quantity_reserved)
            $sourceInventory->update([
                'quantity_reserved' => $sourceInventory->quantity_reserved + $data['quantity'],
                'last_updated' => now(),
            ]);

            // បង្កើតលេខផ្ទេរ (Transfer Number)
            $transferNumber = $this->generateTransferNumber();

            // ទទួលយកព័ត៌មានផលិតផលនិងសាខា
            $product = Product::find($data['product_id']);
            $toBranch = Branches::find($data['to_branch_id']);

            // បង្កើត transfer_out movement
            $transferOut = StockMovement::create([
                'inventory_id' => $sourceInventory->inventory_id,
                'product_id' => $data['product_id'],
                'branch_id' => $data['from_branch_id'],
                'user_id' => $data['user_id'],
                'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
                'quantity' => 0, // មិនទាន់ដកចេញ (pending)
                'quantity_before' => $sourceInventory->quantity_on_hand,
                'quantity_after' => $sourceInventory->quantity_on_hand,
                'reference_number' => $transferNumber,
                'notes' => json_encode([
                    'status' => 'pending',
                    'to_branch_id' => $data['to_branch_id'],
                    'to_branch_name' => $toBranch->branch_name,
                    'transfer_quantity' => $data['quantity'],
                    'user_notes' => $data['notes'] ?? null,
                    'initiated_at' => now()->toDateTimeString(),
                ]),
            ]);

            // ត្រឡប់លទ្ធផល
            return [
                'transfer_number' => $transferNumber,
                'status' => 'pending',
                'from_branch' => Branches::find($data['from_branch_id']),
                'to_branch' => $toBranch,
                'product' => $product,
                'quantity' => $data['quantity'],
                'quantity_reserved' => $sourceInventory->fresh()->quantity_reserved,
                'quantity_available' => $sourceInventory->fresh()->quantity_available,
                'movement' => $transferOut->load(['branch', 'product', 'user']),
                'message' => 'ការផ្ទេរត្រូវបានចាប់ផ្តើម - ស្តុកត្រូវបានកក់ទុក',
            ];
        });
    }

    /**
     * ════════════════════════════════════════════════════════════
     * CONFIRM TRANSFER - បញ្ជាក់ការផ្ទេរ
     * ════════════════════════════════════════════════════════════
     * 
     * ដំណើរការ:
     * ① រក transfer_out movement តាម reference_number
     * ② ដកស្តុកពីសាខាប្រភព (deduct from source)
     * ③ លែងកក់ទុក (release reserved stock)
     * ④ បន្ថែមស្តុកទៅសាខាគោលដៅ (add to destination)
     * ⑤ បង្កើត transfer_in movement
     * ⑥ កែប្រែ status → completed
     * 
     * នៅដំណាក់កាលនេះ:
     * - ស្តុកត្រូវបានដកចេញពីប្រភព
     * - ស្តុកត្រូវបានបន្ថែមទៅគោលដៅ
     * - មិនអាចលុបចោលបាន (cannot cancel)
     */
    public function confirmTransfer(string $transferNumber, string $userId): array
    {
        return DB::transaction(function () use ($transferNumber, $userId) {
            // រក transfer_out movement
            $transferOut = StockMovement::where('reference_number', $transferNumber)
                ->where('movement_type', StockMovement::TYPE_TRANSFER_OUT)
                ->lockForUpdate()
                ->firstOrFail();

            // ពិនិត្យ status
            $notes = json_decode($transferOut->notes, true);

            if ($notes['status'] !== 'pending') {
                throw new \Exception('ការផ្ទេរនេះត្រូវបានបញ្ជាក់រួចហើយ! Status: ' . $notes['status']);
            }

            // ទាញយកព័ត៌មាន
            $quantity = $notes['transfer_quantity'];
            $toBranchId = $notes['to_branch_id'];

            // រកស្តុកសាខាប្រភព
            $sourceInventory = Inventory::lockForUpdate()
                ->findOrFail($transferOut->inventory_id);

            // ដកស្តុកពីប្រភព (Deduct from source)
            $quantityBeforeDeduct = $sourceInventory->quantity_on_hand;
            
            $sourceInventory->update([
                'quantity_on_hand' => $sourceInventory->quantity_on_hand - $quantity,
                'quantity_reserved' => $sourceInventory->quantity_reserved - $quantity,
                'last_updated' => now(),
            ]);

            // កែប្រែ transfer_out movement
            $notes['status'] = 'completed';
            $notes['confirmed_by'] = $userId;
            $notes['confirmed_at'] = now()->toDateTimeString();

            $transferOut->update([
                'quantity' => $quantity,
                'quantity_before' => $quantityBeforeDeduct,
                'quantity_after' => $quantityBeforeDeduct - $quantity,
                'notes' => json_encode($notes),
                'updated_at' => now(),
            ]);

            // រក/បង្កើត ស្តុកសាខាគោលដៅ
            $destInventory = Inventory::firstOrCreate(
                [
                    'branch_id' => $toBranchId,
                    'product_id' => $transferOut->product_id,
                ],
                [
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                    'reorder_level' => $sourceInventory->reorder_level ?? 10,
                    'reorder_quantity' => $sourceInventory->reorder_quantity ?? 50,
                    'last_updated' => now(),
                ]
            );

            // បន្ថែមស្តុកទៅគោលដៅ (Add to destination)
            $quantityBeforeAdd = $destInventory->quantity_on_hand;

            $destInventory->update([
                'quantity_on_hand' => $destInventory->quantity_on_hand + $quantity,
                'last_updated' => now(),
            ]);

            // បង្កើត transfer_in movement
            $fromBranch = Branches::find($transferOut->branch_id);

            $transferIn = StockMovement::create([
                'inventory_id' => $destInventory->inventory_id,
                'product_id' => $transferOut->product_id,
                'branch_id' => $toBranchId,
                'user_id' => $userId,
                'movement_type' => StockMovement::TYPE_TRANSFER_IN,
                'quantity' => $quantity,
                'quantity_before' => $quantityBeforeAdd,
                'quantity_after' => $quantityBeforeAdd + $quantity,
                'reference_number' => $transferNumber,
                'notes' => json_encode([
                    'status' => 'completed',
                    'from_branch_id' => $transferOut->branch_id,
                    'from_branch_name' => $fromBranch->branch_name,
                    'confirmed_by' => $userId,
                    'confirmed_at' => now()->toDateTimeString(),
                ]),
            ]);

            // ត្រឡប់លទ្ធផល
            return [
                'transfer_number' => $transferNumber,
                'status' => 'completed',
                'from_branch' => $fromBranch,
                'to_branch' => Branches::find($toBranchId),
                'product' => Product::find($transferOut->product_id),
                'quantity' => $quantity,
                'source_inventory' => $sourceInventory->fresh(),
                'destination_inventory' => $destInventory->fresh(),
                'transfer_out' => $transferOut->fresh()->load(['branch', 'product', 'user']),
                'transfer_in' => $transferIn->load(['branch', 'product', 'user']),
                'message' => 'ការផ្ទេរបានបញ្ជាក់',
            ];
        });
    }

    /**
     * Cancel transfer
     */
    public function cancelTransfer(string $transferNumber, string $userId, string $reason): array
    {
        return DB::transaction(function () use ($transferNumber, $userId, $reason) {
            $transferOut = StockMovement::where('reference_number', $transferNumber)
                ->where('movement_type', StockMovement::TYPE_TRANSFER_OUT)
                ->lockForUpdate()
                ->firstOrFail();

            $notes = json_decode($transferOut->notes, true);

            if ($notes['status'] !== 'pending') {
                throw new \Exception('មិនអាចលុបចោលបាន! Status: ' . $notes['status']);
            }

            // Release reserved stock
            $sourceInventory = Inventory::lockForUpdate()
                ->findOrFail($transferOut->inventory_id);

            $sourceInventory->update([
                'quantity_reserved' => $sourceInventory->quantity_reserved - $notes['transfer_quantity'],
                'last_updated' => now(),
            ]);

            // Update movement
            $notes['status'] = 'cancelled';
            $notes['cancelled_by'] = $userId;
            $notes['cancelled_at'] = now()->toDateTimeString();
            $notes['cancellation_reason'] = $reason;

            $transferOut->update([
                'notes' => json_encode($notes),
                'updated_at' => now(),
            ]);

            return [
                'transfer_number' => $transferNumber,
                'status' => 'cancelled',
                'message' => 'ការផ្ទេរត្រូវបានលុបចោល',
                'movement' => $transferOut->fresh()->load(['branch', 'product', 'user']),
            ];
        });
    }

    /**
     * Get transfer details
     */
    public function getTransferByNumber(string $transferNumber): array
    {
        $movements = StockMovement::where('reference_number', $transferNumber)
            ->whereIn('movement_type', [
                StockMovement::TYPE_TRANSFER_OUT,
                StockMovement::TYPE_TRANSFER_IN
            ])
            ->with(['branch', 'product', 'user'])
            ->get();

        if ($movements->isEmpty()) {
            throw new \Exception('រកមិនឃើញការផ្ទេរ: ' . $transferNumber);
        }

        $transferOut = $movements->firstWhere('movement_type', StockMovement::TYPE_TRANSFER_OUT);
        $transferIn = $movements->firstWhere('movement_type', StockMovement::TYPE_TRANSFER_IN);

        $notes = json_decode($transferOut->notes, true);

        return [
            'transfer_number' => $transferNumber,
            'status' => $notes['status'] ?? 'unknown',
            'from_branch' => $transferOut->branch,
            'to_branch' => $transferIn ? $transferIn->branch : Branches::find($notes['to_branch_id'] ?? null),
            'product' => $transferOut->product,
            'quantity' => $notes['transfer_quantity'] ?? abs($transferOut->quantity),
            'initiated_at' => $notes['initiated_at'] ?? null,
            'confirmed_at' => $notes['confirmed_at'] ?? null,
            'transfer_out_movement' => $transferOut,
            'transfer_in_movement' => $transferIn,
        ];
    }

    /**
     * Generate transfer number
     */
    protected function generateTransferNumber(): string
    {
        $prefix = 'TRF';
        $date = date('Ymd');
        $random = strtoupper(Str::random(6));
        
        return "{$prefix}-{$date}-{$random}";
    }
}
