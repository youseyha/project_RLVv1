<?php
// routes/api.php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\TokenController;
use App\Http\Controllers\UsageTrackingController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Http\Request; 
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Version 1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // PUBLIC ROUTES (No Authentication)
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    // SUBSCRIPTION MANAGEMENT (Public - No subscription required)
    Route::prefix('subscription_plans')->group(function () {
        // Public - View available plans
        Route::get('/', [SubscriptionPlanController::class, 'index']);
        Route::get('/{id}', [SubscriptionPlanController::class, 'show']);
    });
    Route::prefix('subscriptions')->group(function () {
        Route::get('/plans', [SubscriptionController::class, 'listPlans']);
    });

    // ════════════════════════════════════════════════════════════
    // WEBHOOK ROUTES (Public with signature verification)
    // ════════════════════════════════════════════════════════════
    Route::prefix('webhooks')->group(function () {
        
        Route::post('/aba', [PaymentWebhookController::class, 'aba'])
            ->middleware(VerifyWebhookSignature::class . ':aba')
            ->name('webhook.aba');
        
        Route::post('/wing', [PaymentWebhookController::class, 'wing'])
            ->middleware(VerifyWebhookSignature::class . ':wing')
            ->name('webhook.wing');
        
        Route::post('/khqr', [PaymentWebhookController::class, 'khqr'])
            ->middleware(VerifyWebhookSignature::class . ':khqr')
            ->name('webhook.khqr');
    });

    // AUTHENTICATED ROUTES
    Route::middleware(['auth:sanctum','spatie.team','tenant'])->group(function () {

        // AUTH ENDPOINTS
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
        });

        // TOKEN MANAGEMENT
        Route::prefix('tokens')->group(function () {
            Route::get('/', [TokenController::class, 'index']);
            Route::post('/', [TokenController::class, 'store']);
            Route::delete('/{tokenId}', [TokenController::class, 'destroy']);
            Route::delete('/', [TokenController::class, 'destroyAll']);
        });
        // ───────────────────────────────────────────────────────────
        // SUPER ADMIN - PLAN MANAGEMENT (No tenant/subscription required)
        // ───────────────────────────────────────────────────────────
        Route::prefix('subscriptions/plans')->middleware(['role:super_admin']) ->group(function () {
                
                // Plan CRUD
                Route::post('/', [SubscriptionPlanController::class, 'store']);
                Route::put('/{id}', [SubscriptionPlanController::class, 'update']);
                Route::delete('/{id}', [SubscriptionPlanController::class, 'destroy']);
                
                // Plan Actions
                Route::patch('/{id}/toggle-status', [SubscriptionPlanController::class, 'toggleStatus']);
                Route::get('/{id}/statistics', [SubscriptionPlanController::class, 'statistics']);
        });
        // ───────────────────────────────────────────────────────────
        // SUBSCRIPTION MANAGEMENT (No subscription required to subscribe)
        // ───────────────────────────────────────────────────────────
        Route::prefix('subscriptions')->group(function () {
            
            // Subscribe (No existing subscription required)
            Route::post('/', [SubscriptionController::class, 'subscribe'])->middleware(['role:admin']);
            
            // View own subscriptions
            Route::get('/', [SubscriptionController::class, 'index']);
            Route::get('/current', [SubscriptionController::class, 'current'])->middleware(['role:admin|manager']);
            Route::get('/{id}', [SubscriptionController::class, 'show']);

            // Reactivate expired subscription
            Route::post('/{id}/reactivate', [SubscriptionController::class, 'reactivate'])->middleware(['role:super_admin']);
            
            // Subscription Actions (Require existing subscription)
            Route::middleware(['subscription'])->group(function () {
                Route::put('/{id}', [SubscriptionController::class, 'update'])->middleware(['role:admin']);
                Route::post('/{id}/upgrade', [SubscriptionController::class, 'upgrade'])->middleware(['role:admin']);
                Route::post('/{id}/downgrade', [SubscriptionController::class, 'downgrade'])->middleware(['role:admin']);
                Route::post('/{id}/cancel', [SubscriptionController::class, 'cancel'])->middleware(['role:admin']);
            });
        });
        
         Route::prefix('payments')->group(function () {
        // Return URLs (public but with payment ID)
            Route::get('/{id}/return', [PaymentController::class, 'return'])->name('payment.return');
            Route::get('/{id}/success', [PaymentController::class, 'success'])->name('payment.success');
            Route::get('/{id}/cancel', [PaymentController::class, 'cancel'])->name('payment.cancel');
        });
        // TENANT-SCOPED ROUTES
        Route::middleware(['tenant', 'subscription','branch'])->group(function () {

            // ADMIN ONLY ROUTES
            Route::middleware(['role:admin'])->group(function () {
                
                // User Management
                Route::prefix('users')->group(function () {
                    //statistics
                    Route::get('/statistics', [UserController::class, 'statistics']);

                    Route::get('/', [UserController::class, 'index']);
                    Route::post('/', [UserController::class, 'store'])->middleware(['limit:users']);// Users - Check Limit

                    Route::get('/{id}', [UserController::class, 'show']);
                    Route::put('/{id}', [UserController::class, 'update']);
                    Route::delete('/{id}', [UserController::class, 'destroy']);
                    // User specific actions
                    Route::post('/{id}/assign-role', [UserController::class, 'assignRole']);
                    Route::post('/{id}/toggle-status', [UserController::class, 'toggleStatus']);
                });

                // Branch Management
                Route::prefix('branches')->group(function () {
                    Route::get('/', [BranchController::class, 'index']);
                    Route::post('/', [BranchController::class, 'store'])->middleware(['limit:branches']);// Branches - Check Limit
                    Route::get('/{id}', [BranchController::class, 'show']);
                    Route::put('/{id}', [BranchController::class, 'update']);
                    // Assign or Change manager for existing branch
                    Route::post('/{id}/assign-manager', [BranchController::class, 'assignManager']);
                    // Remove manager from branch                    Route::post('/{id}/remove-manager', [BranchController::class, 'removeManager']);
                    Route::post('/{id}/remove-manager', [BranchController::class, 'removeManager']);
                    
                    Route::delete('/{id}', [BranchController::class, 'destroy']);
                    // Branch specific actions
                    Route::post('/{id}/toggle-status', [BranchController::class, 'toggleStatus']);
                    Route::get('/{id}/users', [BranchController::class, 'users']);
                    Route::get('/{id}/statistics', [BranchController::class, 'statistics']);
                });

                // Role & Permission Management
                Route::prefix('roles')->group(function () {
                    Route::get('/', [RoleController::class, 'index']);
                    Route::get('/permissions', [RoleController::class, 'permissions']);
                    Route::post('/{roleId}/assign-permissions', [RoleController::class, 'assignPermissions']);
                });

                // USAGE TRACKING (Require subscription)
                Route::prefix('usage')->middleware(['subscription'])->group(function () {
                    Route::get('/current', [UsageTrackingController::class, 'current']);
                    Route::get('/history', [UsageTrackingController::class, 'history']);
                    Route::get('/check/{type}', [UsageTrackingController::class, 'checkLimit']);
                    Route::get('/alerts', [UsageTrackingController::class, 'alerts']);
                    Route::get('/forecast', [UsageTrackingController::class, 'forecast']);
                });

                Route::prefix('payment-methods')->group(function () {
                    // List payment methods
                    Route::get('/', [PaymentMethodController::class, 'index']);
                    // Create payment method
                    Route::post('/', [PaymentMethodController::class, 'store']);
                    // Delete payment method
                    Route::delete('/{id}', [PaymentMethodController::class, 'destroy']);
                    // Update payment method
                    Route::put('/{id}', [PaymentMethodController::class, 'update']);
                    // Toggle status 
                    Route::post('/{id}/toggle-status', [PaymentMethodController::class, 'toggleStatus']);
                });

                // // PROTECTED ROUTES - Require Active Subscription
                // Route::middleware(['subscription'])->group(function () {
                    
                //     // Analytics - Require Feature
                //     Route::middleware(['feature:analytics'])->group(function () {
                //         Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
                //         Route::get('/analytics/reports', [AnalyticsController::class, 'reports']);
                //     });
                    
                //     // API Access - Require Feature
                //     Route::middleware(['feature:api_access'])->group(function () {
                //         Route::get('/api/tokens', [ApiTokenController::class, 'index']);
                //         Route::post('/api/tokens', [ApiTokenController::class, 'create']);
                //     });
                // });
            });

            // ADMIN & MANAGER ROUTES
            Route::middleware(['role:admin|manager'])->group(function () {
                
                // View users (read-only for managers)
                Route::get('users', [UserController::class, 'index']);
                Route::get('users/{id}', [UserController::class, 'show']);
                
                // View branches (read-only for managers)
                Route::get('branches', [BranchController::class, 'index']);
                Route::get('branches/{id}', [BranchController::class, 'show']);

                // Categories
                Route::prefix('categories')->group(function () {
                    Route::get('/', [CategoryController::class, 'index']);
                    Route::post('/', [CategoryController::class, 'store']);
                    Route::get('/{id}', [CategoryController::class, 'show']);
                    Route::put('/{id}', [CategoryController::class, 'update']);
                    Route::delete('/{id}', [CategoryController::class, 'destroy']);
                    Route::post('/{id}/toggle-status', [CategoryController::class, 'toggleStatus']);
                });
            
                // Products
                Route::prefix('products')->group(function () {
                    Route::get('/low-stock', [ProductController::class, 'lowStock']);
                    Route::post('/bulk-update-stock', [ProductController::class, 'bulkUpdateStock']);

                    Route::get('/', [ProductController::class, 'index']);
                    Route::post('/', [ProductController::class, 'store']);
                    Route::get('/{id}', [ProductController::class, 'show']);
                    Route::put('/{id}', [ProductController::class, 'update']);
                    Route::delete('/{id}', [ProductController::class, 'destroy']);
                    Route::post('/{id}/toggle-status', [ProductController::class, 'toggleStatus']);
                });

                // Inventory routes
                Route::prefix('inventory')->group(function () {
                    // STOCK RECEIPT API
                    Route::post('/receive', [InventoryController::class, 'receiveStock']);
                    // STOCK LEVEL API
                    Route::get('/', [InventoryController::class, 'index']);
                    Route::get('/stock-value', [InventoryController::class, 'stockValue']);
                    Route::get('/{id}', [InventoryController::class, 'show']);
                    
                    // ADJUSTMENT API
                    Route::post('/{id}/adjust', [InventoryController::class, 'adjustStock']);
                    Route::post('/bulk-adjust', [InventoryController::class, 'bulkAdjust']);
                    Route::post('/transfer', [InventoryController::class, 'transfer']);
                    
                    // ALERT API
                    Route::prefix('alerts')->group(function () {
                        Route::get('/low-stock', [InventoryController::class, 'lowStock']);
                        Route::get('/out-of-stock', [InventoryController::class, 'outOfStock']);
                        Route::get('/reorder-suggestions', [InventoryController::class, 'reorderSuggestions']);
                        Route::get('/summary', [InventoryController::class, 'alertsSummary']);
                    });
                    
                    // STOCK RESERVATION
                    Route::post('/{id}/reserve', [InventoryController::class, 'reserveStock']);
                    Route::post('/{id}/release', [InventoryController::class, 'releaseReservedStock']);
                });
                //Transaction
                 Route::prefix('transactions')->group(function () {
                    Route::get('/reports/summary', [TransactionController::class, 'salesSummary']);
                    Route::post('/{id}/cancel', [TransactionController::class, 'cancel']);
                    Route::post('/{id}/refund', [TransactionController::class, 'refund']);
                });
                // STOCK TRANSFERS
                Route::prefix('stock-transfers')->group(function () {
        
                    // List transfers
                    Route::get('/', [StockTransferController::class, 'index']);
                    
                    // Get single transfer
                    Route::get('/{transferNumber}', [StockTransferController::class, 'show']);
                    
                    // Initiate transfer
                    Route::post('/', [StockTransferController::class, 'initiate']);
                    
                    // Confirm transfer
                    Route::post('/{transferNumber}/confirm', [StockTransferController::class, 'confirm']);
                    
                    // Cancel transfer
                    Route::post('/{transferNumber}/cancel', [StockTransferController::class, 'cancel']);
                    
                    // Get pending transfers
                    Route::get('/pending/list', [StockTransferController::class, 'pending']);
                    
                     // Get low stock items
                    Route::get('/low-stock/list', [StockTransferController::class, 'lowStock']);
                    
                    // Trigger auto-reorder
                    Route::post('/auto-reorder/trigger', [StockTransferController::class, 'triggerAutoReorder']);
                });
                // STOCK ADJUSTMENTS
                Route::prefix('stock-adjustments')->group(function () {
                    Route::post('/', [StockAdjustmentController::class, 'adjust']);
                    Route::post('/bulk', [StockAdjustmentController::class, 'bulkAdjust']);
                    Route::post('/damage', [StockAdjustmentController::class, 'damage']);
                    Route::post('/return', [StockAdjustmentController::class, 'return']);
                });
                // STOCK MOVEMENTS HISTORY
                Route::prefix('stock-movements')->group(function () {
                    Route::get('/', [StockMovementController::class, 'index']);
                    Route::get('/recent', [StockMovementController::class, 'recent']);
                    Route::get('/{id}', [StockMovementController::class, 'show']);
                    Route::get('/product/{productId}', [StockMovementController::class, 'byProduct']);
                    Route::get('/branch/{branchId}/summary', [StockMovementController::class, 'branchSummary']);
                });
                Route::prefix('reports')->group(function () {
        
                    // Daily Reports
                    Route::get('/daily', [ReportController::class, 'indexDaily']);
                    Route::get('/daily/{id}', [ReportController::class, 'showDaily']);
                    Route::post('/daily/generate', [ReportController::class, 'generateDaily']);
                    Route::post('/daily/generate-all', [ReportController::class, 'generateAllBranches']);
                    Route::post('/daily/{id}/export', [ReportController::class, 'exportDaily']);
                    Route::get('/daily/{id}/download', [ReportController::class, 'downloadDaily']);
                    Route::delete('/daily/{id}', [ReportController::class, 'destroyDaily']);
                    
                    // Summary & Comparison
                    Route::get('/summary', [ReportController::class, 'summary']);
                    //សម្រាប់ dashboard / charts / business analytics
                    Route::get('/compare', [ReportController::class, 'compare']);
                });

                // Invoices 
                Route::prefix('invoices')->group(function () {
                    Route::get('/', [InvoiceController::class, 'index']);
                    // Startistics
                    Route::get('/statistics', [InvoiceController::class, 'statistics']);

                    Route::get('/{id}', [InvoiceController::class, 'show']);
                    // Store new invoice (e.g. for subscription renewal)
                    Route::post('/', [InvoiceController::class, 'store']);
                    //sand mail
                    Route::post('/{id}/send', [InvoiceController::class, 'send'])
                        ->name('invoices.send');
                    // Send reminder email
                    Route::post('/{id}/send-reminder', [InvoiceController::class, 'sendReminder'])
                        ->name('invoices.send-reminder');
                    // Payment & Refund
                    Route::post('/{id}/pay', [InvoiceController::class, 'pay']);
                    Route::post('/{id}/refund', [InvoiceController::class, 'refund']);
                    Route::get('/{id}/payments', [InvoiceController::class, 'paymentHistory']);
                    
                    Route::post('/{id}/cancel', [InvoiceController::class, 'cancel']);
                    Route::get('/{id}/pdf', [InvoiceController::class, 'viewPdf'])
                        ->name('invoices.pdf');
                    Route::get('/{id}/download', [InvoiceController::class, 'downloadPdf'])
                        ->name('invoices.download');
                });

                Route::prefix('payments')->group(function () {
                    // Initiate payments
                    Route::post('/invoices/{id}/initiate', [PaymentController::class, 'initiateInvoicePayment']);
                    Route::post('/transactions/{id}/initiate', [PaymentController::class, 'initiatePOSPayment']);
                    
                    // Check status
                    Route::get('/{id}/status', [PaymentController::class, 'checkStatus']);
                    
                    // Refund
                    Route::post('/{id}/refund', [PaymentController::class, 'refund']);
                    // KHQR payment initiation
                    Route::post('/invoices/{id}/initiate-khqr', [PaymentController::class, 'initiateKHQRPayment'])
                        ->name('payments.initiate-khqr');
                    
                    // Check KHQR payment status (polling)
                    Route::get('/{id}/khqr-status', [PaymentController::class, 'checkKHQRStatus'])
                        ->name('payments.khqr-status');
                });

            });
            // List & Create (Cashier+)
            Route::middleware(['role:admin|manager|cashier'])->group(function () {
                Route::prefix('transactions')->group(function () {
                    Route::get('/today', [TransactionController::class, 'today']);

                    Route::get('/', [TransactionController::class, 'index']);
                    Route::post('/', [TransactionController::class, 'store']);
                    
                    Route::get('/{id}', [TransactionController::class, 'show']);
                    Route::get('/{id}/receipt', [TransactionController::class, 'receipt']);
                    Route::get('/{id}/items', [TransactionController::class, 'items']);
                });
            });
            // BRANCH-SCOPED ROUTES (All roles)
            Route::middleware(['branch'])->group(function () {
                
                Route::get('products', [ProductController::class, 'index']);
                Route::get('products/{id}', [ProductController::class, 'show']);
                Route::get('categories', [CategoryController::class, 'index']);
            });

        });
    });
});
