<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockTransferCollection;
use App\Models\StockMovement;
use App\Models\Inventory;
use App\Services\StockTransferService;
use App\Jobs\ProcessAutoReorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StockTransferController extends Controller
{
    protected $transferService;

    public function __construct(StockTransferService $transferService)
    {
        $this->transferService = $transferService;
    }

    /**
     * GET TRANSFER HISTORY
     */
    public function index(Request $request)
    {
        $tenant = app('tenant');

        // Build query
        $query = StockMovement::with(['branch', 'product', 'user'])
            ->whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->whereIn('movement_type', [
                StockMovement::TYPE_TRANSFER_IN,
                StockMovement::TYPE_TRANSFER_OUT
            ]);

        // Apply filters
        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->product_id) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Paginate
        $movements = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return new StockTransferCollection($movements);
    }

    /**
     * GET SINGLE TRANSF
     * 
     * GET /api/v1/stock-transfers/{transferNumber}
     */
    public function show( string $transferNumber)
    {
        try {
            $transfer = $this->transferService->getTransferByNumber($transferNumber);

            return response()->json([
                'success' => true,
                'data' => $transfer,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * INITIATE TRANSFER
     * 
     * POST /api/v1/stock-transfers
     * 
     * Body:
     * {
     *   "from_branch_id": "uuid",
     *   "to_branch_id": "uuid",
     *   "product_id": "uuid",
     *   "quantity": 10,
     *   "notes": "Optional notes"
     * }
     */
    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'from_branch_id' => 'required|uuid|exists:branches,branch_id',
            'to_branch_id' => 'required|uuid|exists:branches,branch_id|different:from_branch_id',
            'product_id' => 'required|uuid|exists:products,product_id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ], [
            'from_branch_id.required' => 'សូមជ្រើសរើសសាខាប្រភព',
            'to_branch_id.required' => 'សូមជ្រើសរើសសាខាគោលដៅ',
            'to_branch_id.different' => 'សាខាគោលដៅត្រូវខុសពីសាខាប្រភព',
            'product_id.required' => 'សូមជ្រើសរើសផលិតផល',
            'quantity.required' => 'សូមបញ្ចូលចំនួន',
            'quantity.min' => 'ចំនួនត្រូវតែធំជាង 0',
        ]);

        try {
            $validated['user_id'] = Auth::id();

            $transfer = $this->transferService->initiateTransfer($validated);

            return response()->json([
                'success' => true,
                'message' => 'ចាប់ផ្តើមការផ្ទេរស្តុកបានជោគជ័យ',
                'data' => $transfer,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * CONFIRM TRANSFER
     * 
     * POST /api/v1/stock-transfers/{transferNumber}/confirm
     */
    public function confirm( string $transferNumber)
    {
        try {
            $transfer = $this->transferService->confirmTransfer($transferNumber, Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'បញ្ជាក់ការផ្ទេរស្តុកបានជោគជ័យ',
                'data' => $transfer,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * CANCEL TRANSFER
     * 
     * POST /api/v1/stock-transfers/{transferNumber}/cancel
     * 
     * Body:
     * {
     *   "reason": "Cancellation reason"
     * }
     */
    public function cancel(Request $request, string $transferNumber)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ], [
            'reason.required' => 'សូមបញ្ចូលមូលហេតុនៃការលុបចោល',
        ]);

        try {
            $transfer = $this->transferService->cancelTransfer(
                $transferNumber,
                Auth::id(),
                $validated['reason']
            );

            return response()->json([
                'success' => true,
                'message' => 'លុបចោលការផ្ទេរបានជោគជ័យ',
                'data' => $transfer,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET PENDING TRANSFERS
     * 
     * GET /api/v1/stock-transfers/pending/list
     * 
     * Query Parameters:
     * - branch_id: Filter by branch (optional)
     * - direction: outgoing, incoming, or both (default: both)
     */
    public function pending(Request $request)
    {
        $tenant = app('tenant');
        $branchId = $request->branch_id;
        $direction = $request->direction ?? 'both';

        // Get pending transfers
        $query = StockMovement::with(['branch', 'product', 'user'])
            ->whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->where('movement_type', StockMovement::TYPE_TRANSFER_OUT)
            ->where('notes', 'like', '%"status":"pending"%');

        // Filter by branch and direction
        if ($branchId) {
            if ($direction === 'outgoing') {
                $query->where('branch_id', $branchId);
            } elseif ($direction === 'incoming') {
                $query->where('notes', 'like', '%"to_branch_id":"' . $branchId . '"%');
            } else {
                $query->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)
                      ->orWhere('notes', 'like', '%"to_branch_id":"' . $branchId . '"%');
                });
            }
        }

        $movements = $query->latest('created_at')->get();

        // Format data
        $transfers = $movements->map(function ($movement) {
            $notes = json_decode($movement->notes, true);
            return [
                'transfer_number' => $movement->reference_number,
                'movement_type' => $movement->movement_type,
                'from_branch' => [
                    'branch_id' => $movement->branch->branch_id,
                    'branch_name' => $movement->branch->branch_name,
                ],
                'to_branch' => $notes ? [
                    'branch_id' => $notes['to_branch_id'] ?? null,
                    'branch_name' => $notes['to_branch_name'] ?? null,
                ] : null,
                'product' => [
                    'product_id' => $movement->product->product_id,
                    'product_name' => $movement->product->product_name,
                    'product_code' => $movement->product->product_code,
                ],
                'quantity' => $notes['transfer_quantity'] ?? 0,
                'status' => $notes['status'] ?? 'unknown',
                'initiated_at' => $notes['initiated_at'] ?? null,
                'initiated_by' => $movement->user ? [
                    'user_id' => $movement->user->user_id,
                    'username' => $movement->user->username,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transfers,
            'count' => $transfers->count(),
        ]);
    }

    /**
     * TRIGGER AUTO-REORDER (MANUAL)
     * 
     * POST /api/v1/stock-transfers/auto-reorder/trigger
     * 
     * Manually trigger the auto-reorder job
     */
    public function triggerAutoReorder()
    {
        try {
            ProcessAutoReorder::dispatch();

            return response()->json([
                'success' => true,
                'message' => 'បានចាប់ផ្តើមការពិនិត្យស្តុកស្វ័យប្រវត្តិ',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET LOW STOCK ITEMS
     * 
     * GET /api/v1/stock-transfers/low-stock
     * 
     * Query Parameters:
     * - branch_id: Filter by branch (optional)
     */
    public function lowStock(Request $request)
    {
        $tenant = app('tenant');

        $query = Inventory::with(['branch', 'product'])
            ->whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_level')
            ->where('quantity_on_hand', '>', 0);

        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        $lowStockItems = $query->orderBy('quantity_on_hand', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $lowStockItems->map(function ($inventory) {
                return [
                    'inventory_id' => $inventory->inventory_id,
                    'branch' => [
                        'branch_id' => $inventory->branch->branch_id,
                        'branch_name' => $inventory->branch->branch_name,
                    ],
                    'product' => [
                        'product_id' => $inventory->product->product_id,
                        'product_name' => $inventory->product->product_name,
                        'product_code' => $inventory->product->product_code,
                    ],
                    'quantity_on_hand' => (float) $inventory->quantity_on_hand,
                    'quantity_reserved' => (float) $inventory->quantity_reserved,
                    'quantity_available' => (float) $inventory->quantity_available,
                    'reorder_level' => (float) $inventory->reorder_level,
                    'reorder_quantity' => (float) $inventory->reorder_quantity,
                    'suggested_order' => (float) $inventory->reorder_quantity,
                    'is_low_stock' => $inventory->is_low_stock,
                    'is_out_of_stock' => $inventory->is_out_of_stock,
                    'urgency' => $inventory->quantity_on_hand <= 5 ? 'critical' : 'high',
                ];
            }),
            'count' => $lowStockItems->count(),
        ]);
    }
}