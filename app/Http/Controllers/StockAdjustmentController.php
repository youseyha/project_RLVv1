<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Http\Requests\BulkAdjustmentRequest;
use App\Http\Resources\StockMovementResource;
use App\Services\StockAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StockAdjustmentController extends Controller
{
    protected $adjustmentService;

    public function __construct(StockAdjustmentService $adjustmentService)
    {
        $this->adjustmentService = $adjustmentService;
    }

    /**
     * API 1: ADJUST STOCK - កែតម្រូវស្តុក
     * 
     * POST /api/v1/stock-adjustments
     * 
     * Request Body:
     * {
     *   "branch_id": "uuid",
     *   "product_id": "uuid",
     *   "adjustment_quantity": 10,      // + = increase, - = decrease
     *   "movement_type": "adjustment",  // adjustment, damage, return
     *   "reason": "Count mismatch",
     *   "notes": "Found 10 more during inventory"
     * }
     * 
     * Use Cases:
     * - រកឃើញខុសពេលរាប់ស្តុក (Inventory count mismatch)
     * - កត់ត្រាស្តុកខូច (Record damaged goods)
     * - ត្រឡប់ស្តុកមកវិញ (Return from customer)
     */
    public function adjust(StockAdjustmentRequest $request)
    {
        try {
            $validated = $request->validated();
            $validated['user_id'] = Auth::id();

            $movement = $this->adjustmentService->adjustStock($validated);

            return response()->json([
                'success' => true,
                'message' => 'កែតម្រូវស្តុកបានជោគជ័យ',
                'data' => new StockMovementResource($movement),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 2: BULK ADJUSTMENT - កែតម្រូវច្រើន
     * 
     * POST /api/v1/stock-adjustments/bulk
     * 
     * Request Body:
     * {
     *   "adjustments": [
     *     {
     *       "branch_id": "uuid",
     *       "product_id": "uuid-1",
     *       "adjustment_quantity": 5,
     *       "movement_type": "adjustment",
     *       "notes": "Extra stock found"
     *     },
     *     {
     *       "branch_id": "uuid",
     *       "product_id": "uuid-2",
     *       "adjustment_quantity": -3,
     *       "movement_type": "damage",
     *       "notes": "Damaged items"
     *     }
     *   ],
     *   "reason": "Monthly stock count"
     * }
     * 
     * Use Case: ពិនិត្យស្តុកប្រចាំខែ (Monthly inventory count)
     */
    public function bulkAdjust(BulkAdjustmentRequest $request)
    {
        try {
            $validated = $request->validated();

            $result = $this->adjustmentService->bulkAdjustment(
                adjustments: $validated['adjustments'],
                userId: Auth::id(),
                reason: $validated['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => "កែតម្រូវបានជោគជ័យ {$result['success_count']} items",
                'data' => [
                    'reference_number' => $result['reference_number'],
                    'success_count' => $result['success_count'],
                    'failed_count' => $result['failed_count'],
                    'movements' => StockMovementResource::collection($result['movements']),
                    'errors' => $result['errors'],
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 3: DAMAGE STOCK - កត់ត្រាស្តុកខូច
     * 
     * POST /api/v1/stock-adjustments/damage
     * 
     * Request Body:
     * {
     *   "branch_id": "uuid",
     *   "product_id": "uuid",
     *   "quantity": 5,
     *   "reason": "Water damage"
     * }
     */
    public function damage(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|uuid|exists:branches,branch_id',
            'product_id' => 'required|uuid|exists:products,product_id',
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $movement = $this->adjustmentService->damageStock(
                branchId: $validated['branch_id'],
                productId: $validated['product_id'],
                quantity: $validated['quantity'],
                userId: Auth::id(),
                reason: $validated['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'កត់ត្រាស្តុកខូចបានជោគជ័យ',
                'data' => new StockMovementResource($movement),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 4: RETURN STOCK - ត្រឡប់ស្តុកមកវិញ
     * 
     * POST /api/v1/stock-adjustments/return
     * 
     * Request Body:
     * {
     *   "branch_id": "uuid",
     *   "product_id": "uuid",
     *   "quantity": 2,
     *   "reason": "Customer return"
     * }
     */
    public function return(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|uuid|exists:branches,branch_id',
            'product_id' => 'required|uuid|exists:products,product_id',
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $movement = $this->adjustmentService->returnStock(
                branchId: $validated['branch_id'],
                productId: $validated['product_id'],
                quantity: $validated['quantity'],
                userId: Auth::id(),
                reason: $validated['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'កត់ត្រាការត្រឡប់ស្តុកបានជោគជ័យ',
                'data' => new StockMovementResource($movement),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}