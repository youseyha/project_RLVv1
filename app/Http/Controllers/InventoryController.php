<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryCollection;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InventoryController extends Controller
{ 
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }
    /**
     * ═══════════════════════════════════════════════════════════
     * STOCK LEVEL API
     * ═══════════════════════════════════════════════════════════
     */
    /**
     * Get inventory list
     */
    public function index(Request $request)
    {
        $tenant = app('tenant');

        $inventory = Inventory::with(['product', 'branch'])
            ->whereHas('branch', function ($query) use ($tenant) {
                $query->where('tenant_id', $tenant->tenant_id);
            })
            ->when($request->branch_id, fn($q, $id) => $q->where('branch_id', $id))
            ->when($request->product_id, fn($q, $id) => $q->where('product_id', $id))
            ->when($request->stock_status, function ($q, $status) {
                if ($status === 'low_stock') {
                    $q->lowStock();
                } elseif ($status === 'out_of_stock') {
                    $q->outOfStock();
                }
            })
            ->when($request->search, function ($q, $search) {
                $q->whereHas('product', function ($query) use ($search) {
                    $query->where('product_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return new InventoryCollection($inventory);
    }
    /**
     * Get single inventory details
     */
    public function show(string $id)
    {
        $tenant = app('tenant');

        $inventory = Inventory::whereHas('branch', function ($query) use ($tenant) {
                $query->where('tenant_id', $tenant->tenant_id);
            })
            ->with(['product', 'branch', 'movements' => function ($query) {
                $query->latest()->limit(10);
            }])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new InventoryResource($inventory),
        ]);
    }
    /**
     * Get stock value by branch
     */
    public function stockValue(Request $request)
    { 
        $request->validate([
            'branch_id' => 'required|uuid|exists:branches,branch_id',
        ]);

        $value = $this->inventoryService->getStockValueByBranch($request->branch_id);

        return response()->json([
            'success' => true,
            'branch_id' => $request->branch_id,
            'total_stock_value' => round($value, 2),
            'currency' => 'USD',
        ]);
    }
    /**
     * ═══════════════════════════════════════════════════════════
     * STOCK RECEIPT API (Purchase/Restock)
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * Receive stock from supplier
     */
    public function receiveStock(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|uuid|exists:branches,branch_id',
            'supplier_name' => 'required|string|max:255',
            'invoice_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,product_id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.reorder_level' => 'nullable|numeric|min:0',
            'items.*.reorder_quantity' => 'nullable|numeric|min:0',
        ]);

        try {
            $result = $this->inventoryService->receiveStock(
                branchId: $validated['branch_id'],
                items: $validated['items'],
                userId: Auth::id(),
                supplierName: $validated['supplier_name'],
                invoiceNumber: $validated['invoice_number'],
                notes: $validated['notes']
            );

            return response()->json([
                'success' => true,
                'message' => 'ទទួលស្តុកបានជោគជ័យ',
                'data' => [
                    'items' => InventoryResource::collection(collect($result['items'])),
                    'reference_number' => $result['reference_number'],
                    'supplier' => $result['supplier'],
                    'invoice_number' => $result['invoice_number'],
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
     * ═══════════════════════════════════════════════════════════
     * ADJUSTMENT API
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * Adjust single inventory
     */
    public function adjustStock(Request $request, string $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01',
            'movement_type' => 'required|in:adjustment_in,adjustment_out,damage,sale,return_to_supplier,return_from_customer',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'required|string',
        ]);

        try {
            $inventory = $this->inventoryService->adjustStock(
                inventoryId: $id,
                quantity: $validated['quantity'],
                movementType: $validated['movement_type'],
                userId: Auth::id(),
                referenceNumber: $validated['reference_number'] ?? null,
                notes: $validated['notes']
            );

            return response()->json([
                'success' => true,
                'message' => 'កែប្រែស្តុកបានជោគជ័យ',
                'data' => new InventoryResource($inventory),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Bulk adjustment
     */
    public function bulkAdjust(Request $request)
    {
        $validated = $request->validate([
            'adjustments' => 'required|array|min:1',
            'adjustments.*.inventory_id' => 'required|uuid|exists:inventories,inventory_id',
            'adjustments.*.quantity' => 'required|numeric|min:0.01',
            'adjustments.*.movement_type' => 'required|in:adjustment_in,adjustment_out,damage,sale,return_to_supplier,return_from_customer',
            'adjustments.*.notes' => 'nullable|string',
        ]);

        try {
            $result = $this->inventoryService->bulkAdjustment(
                adjustments: $validated['adjustments'],
                userId: Auth::id()
            );

            return response()->json([
                'success' => true,
                'message' => 'កែប្រែស្តុកច្រើនបានជោគជ័យ',
                'data' => [
                    'items' => InventoryResource::collection(collect($result['items'])),
                    'reference_number' => $result['reference_number'],
                    'total_adjusted' => count($result['items']),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Transfer stock between branches
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'from_branch_id' => 'required|uuid|exists:branches,branch_id',
            'to_branch_id' => 'required|uuid|exists:branches,branch_id|different:from_branch_id',
            'product_id' => 'required|uuid|exists:products,product_id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        try {
            $result = $this->inventoryService->transferStock(
                fromBranchId: $validated['from_branch_id'],
                toBranchId: $validated['to_branch_id'],
                productId: $validated['product_id'],
                quantity: $validated['quantity'],
                userId: Auth::id(),
                notes: $validated['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'ផ្ទេរស្តុកបានជោគជ័យ',
                'data' => [
                    'from_inventory' => new InventoryResource($result['from_inventory']),
                    'to_inventory' => new InventoryResource($result['to_inventory']),
                    'reference_number' => $result['reference_number'],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    /**
     * ═══════════════════════════════════════════════════════════
     * ALERT API
     * ═══════════════════════════════════════════════════════════
     */
    /**
     * Get low stock items
     */
    public function lowStock(Request $request)
    {
        $lowStock = $this->inventoryService->getLowStockItems(
            branchId: $request->branch_id
        );

        return response()->json([
            'success' => true,
            'data' => InventoryResource::collection($lowStock),
            'count' => $lowStock->count(),
        ]);
    }
    /**
     * Get out of stock items
     */
    public function outOfStock(Request $request)
    {
        $outOfStock = $this->inventoryService->getOutOfStockItems(
            branchId: $request->branch_id
        );

        return response()->json([
            'success' => true,
            'data' => InventoryResource::collection($outOfStock),
            'count' => $outOfStock->count(),
        ]);
    }
    /**
     * Get reorder suggestions
     */
    public function reorderSuggestions(Request $request)
    {
        $suggestions = $this->inventoryService->getReorderSuggestions(
            branchId: $request->branch_id
        );

        return response()->json([
            'success' => true,
            'data' => $suggestions,
            'total_suggestions' => count($suggestions),
            'total_estimated_cost' => array_sum(array_column($suggestions, 'estimated_cost')),
        ]);
    }
    /**
     * Get all alerts summary
     */
    public function alertsSummary(Request $request)
    {
        $tenant = app('tenant');

        $query = Inventory::whereHas('branch', function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->tenant_id);
        });

        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        $lowStockCount = (clone $query)->lowStock()->count();
        $outOfStockCount = (clone $query)->outOfStock()->count();
        $criticalCount = (clone $query)->where('quantity_on_hand', '<=', 5)->count();

        return response()->json([
            'success' => true,
            'summary' => [
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'critical_count' => $criticalCount,
                'total_alerts' => $lowStockCount + $outOfStockCount,
            ],
        ]);
    }
     /**
     * ═══════════════════════════════════════════════════════════
     * ADDITIONAL HELPER APIs
     * ═══════════════════════════════════════════════════════════
     */
    /**
     * Reserve stock
     */
    public function reserveStock(Request $request,string $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01',
        ]);

        try {
            $inventory = $this->inventoryService->reserveStock(
                inventoryId: $id,
                quantity: $validated['quantity']
            );

            return response()->json([
                'success' => true,
                'message' => 'កក់ស្តុកបានជោគជ័យ',
                'data' => new InventoryResource($inventory),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Release reserved stock
     */
    public function releaseReservedStock(Request $request,string $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01',
        ]);

        try {
            $inventory = $this->inventoryService->releaseReservedStock(
                inventoryId: $id,
                quantity: $validated['quantity']
            );

            return response()->json([
                'success' => true,
                'message' => 'បញ្ចេញស្តុកដែលបានកក់បានជោគជ័យ',
                'data' => new InventoryResource($inventory),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}