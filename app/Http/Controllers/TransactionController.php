<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransactionCollection;
use App\Http\Resources\TransactionItemResource;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\TransactionService;
use App\Traits\GeneratesReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    use GeneratesReceipt;

    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Get transactions list
     */
    public function index(Request $request)
    {
        $tenant = app('tenant');

        $transactions = Transaction::with(['items.product', 'branch', 'user'])
            ->whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->when($request->branch_id, fn($q, $id) => $q->where('branch_id', $id))
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->date_from, fn($q, $date) => $q->whereDate('transaction_date', '>=', $date))
            ->when($request->date_to, fn($q, $date) => $q->whereDate('transaction_date', '<=', $date))
            ->latest('transaction_date')
            ->paginate($request->per_page ?? 20);

        return new TransactionCollection($transactions);
    }

    /**
     * Create new transaction (Sale)
     */
    public function store(Request $request)
    {
        $validated = $request->validate(
            [
                'branch_id' => 'required|uuid|exists:branches,branch_id',
                'terminal_id' => 'nullable|uuid',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|uuid|exists:products,product_id',
                'items.*.quantity' => 'required|numeric|min:0.01',
                'items.*.discount' => 'nullable|numeric|min:0',
                'discount_amount' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string',
            ],
            [
                'branch_id.required' => 'សូមជ្រើសរើសសាខា',
                'items.required' => 'សូមបញ្ចូលផលិតផលយ៉ាងហោចណាស់មួយ',
                'items.*.product_id.required' => 'សូមជ្រើសរើសផលិតផល',
                'items.*.quantity.required' => 'សូមបញ្ចូលចំនួន',
                'items.*.quantity.min' => 'ចំនួនត្រូវតែធំជាង 0',
            ]
        );

        try {
            $validated['user_id'] = Auth::id();
 
            $transaction = $this->transactionService->processTransaction($validated);

            return response()->json([
                'success' => true,
                'message' => 'ប្រតិបត្តិការបានជោគជ័យ',
                'data' => new TransactionResource($transaction),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get single transaction
     */
    public function show(string $id)
    {
        $tenant = app('tenant');

        $transaction = Transaction::whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->with(['items.product', 'branch', 'user'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new TransactionResource($transaction),
        ]);
    }
    /**
     * Purpose: មើលបញ្ជីផលិតផលក្នុងប្រតិបត្តិការ
     * What it does:
     * - Get all items in a transaction
     * - Include product details
     * - Return formatted item list
     * 
     * Use Case: When you want ONLY the items, not full transaction
     */
    public function items(string $id)
    {
        $tenant = app('tenant');

        $transaction = Transaction::whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->with(['items.product'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $transaction->transaction_id,
                'transaction_number' => $transaction->transaction_number,
                'items' => TransactionItemResource::collection($transaction->items),
                'items_count' => $transaction->items->count(),
                'total_quantity' => $transaction->items->sum('quantity'),
                'total_sales' => $transaction->items->sum('line_total'),
            ],
        ]);
    }

    /**
     * Cancel transaction
     */
    public function cancel(Request $request,string $id)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        try {
            $transaction = $this->transactionService->cancelTransaction(
                transactionId: $id,
                userId: Auth::id(),
                reason: $validated['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'លុបចោលប្រតិបត្តិការបានជោគជ័យ',
                'data' => new TransactionResource($transaction),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Process refund
     */
    public function refund(Request $request,string $id)
    {
        $validated = $request->validate(
            [
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|uuid|exists:products,product_id',
                'items.*.quantity' => 'required|numeric|min:0.01',
                'reason' => 'nullable|string',
            ], 
            [
                'items.required' => 'សូមជ្រើសរើសផលិតផលដែលត្រូវសងវិញ',
                'items.*.product_id.required' => 'សូមជ្រើសរើសផលិតផល',
                'items.*.quantity.required' => 'សូមបញ្ចូលចំនួន',
            ]
        );

        try {
            $refund = $this->transactionService->processRefund(
                transactionId: $id,
                items: $validated['items'],
                userId: Auth::id(),
                reason: $validated['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'សងប្រាក់វិញបានជោគជ័យ',
                'data' => new TransactionResource($refund),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Generate receipt PDF
     */
    public function receipt(string $id)
    {
        $tenant = app('tenant');

        $transaction = Transaction::with(['items.product', 'branch', 'user'])
            ->whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->findOrFail($id);

        return $this->generateReceipt($transaction);
    }

    /**
     * Get sales summary
     */
    public function salesSummary(Request $request)
    {
        $tenant = app('tenant');
        $branchId = $request->branch_id;

        $query = Transaction::whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            });

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        if ($request->date_from) {
            $query->whereDate('transaction_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('transaction_date', '<=', $request->date_to);
        }

       // Completed sales
        $completedQuery = (clone $query)
            ->where('status', 'completed');

        // Refunded sales
        $refundQuery = (clone $query)
            ->where('status', 'refunded');
        // Calculate statistics
        $totalSales = (clone $completedQuery)->sum('total_amount');
        $totalRefunds = (clone $refundQuery)->sum('total_amount');
        $netSales = $totalSales - $totalRefunds;
        $totalTransactions = (clone $completedQuery)->count();
        $totalDiscount = (clone $completedQuery)->sum('discount_amount');
        $totalTax = (clone $completedQuery)->sum('tax_amount');

        // Top selling products (Net Sales)
    $topProducts = TransactionItem::query()
                    ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
                    ->join('products', 'transaction_items.product_id', '=', 'products.product_id')
                    ->whereHas('transaction.branch', function ($q) use ($tenant) {
                        $q->where('tenant_id', $tenant->tenant_id);
                    })

                    // branch filter
                    ->when($branchId, function ($q) use ($branchId) {
                        $q->where('transactions.branch_id', $branchId);
                    })

                    // date filters
                    ->when($request->date_from, function ($q) use ($request) {
                        $q->whereDate('transactions.transaction_date', '>=', $request->date_from);
                    })

                    ->when($request->date_to, function ($q) use ($request) {
                        $q->whereDate('transactions.transaction_date', '<=', $request->date_to);
                    })

                    ->selectRaw("
                        products.product_name,

                        SUM(
                            CASE
                                WHEN transactions.status = 'completed'
                                THEN transaction_items.quantity

                                WHEN transactions.status = 'refunded'
                                THEN -transaction_items.quantity

                                ELSE 0
                            END
                        ) as total_quantity,

                        SUM(
                            CASE
                                WHEN transactions.status = 'completed'
                                THEN transaction_items.line_total

                                WHEN transactions.status = 'refunded'
                                THEN -transaction_items.line_total

                                ELSE 0
                            END
                        ) as total_sales
                    ")

                    ->groupBy('products.product_id', 'products.product_name')
                    ->orderByDesc('total_quantity')
                    ->limit(10)
                    ->get();

        return response()->json([
            'success' => true,
            'summary' => [
                'total_sales' => round($totalSales, 2),
                'total_refunds' => round($totalRefunds, 2),
                'net_sales' => round($netSales, 2),
                'total_transactions' => $totalTransactions,
                'average_transaction' => $totalTransactions > 0 ? round($netSales / $totalTransactions, 2) : 0,
                'total_discount' => round($totalDiscount, 2),
                'total_tax' => round($totalTax, 2),
            ],
            'top_products' => $topProducts,
        ]);
    }
    /*
     * Purpose: ប្រតិបត្តិការថ្ងៃនេះ
    */
    public function today(Request $request)
    {
        $tenant = app('tenant');

        $transactions = Transaction::with(['items.product', 'branch', 'user'])
            ->whereHas('branch', function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->tenant_id);
            })
            ->when($request->branch_id, fn($q, $id) => $q->where('branch_id', $id))
            ->whereDate('transaction_date', today())
            ->latest('transaction_date')
            ->get();

        $summary = [
            'total_sales' => $transactions->where('status', 'completed')->sum('total_amount'),
            'total_transactions' => $transactions->where('status', 'completed')->count(),
            'pending_count' => $transactions->where('status', 'pending')->count(),
            'refunded_count' => $transactions->where('status', 'refunded')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => TransactionResource::collection($transactions),
            'summary' => $summary,
        ]);
    }
}