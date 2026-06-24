<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentMethodController extends Controller
{
    /**
     * Create payment method
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'method_name' => 'required|string|max:100',
            'method_type' => 'required|in:credit_card,bank_transfer,cash,e_wallet',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        //Set default payment method if requested
        if ($validated['is_default'] ?? false) {
            PaymentMethod::where('tenant_id',Auth::user()->tenant_id)
            ->update([
                'is_default' => false
            ]);
        }

        //Check if method name already exists for tenant
        $exists = PaymentMethod::where('tenant_id', Auth::user()->tenant_id)
            ->where('method_name', $validated['method_name'])
            ->exists();                     

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Payment method with this name already exists',
            ], 400);
        }

        $paymentMethod = PaymentMethod::create([
            'tenant_id' => Auth::user()->tenant_id,
            'method_name' => $validated['method_name'],
            'method_type' => $validated['method_type'],
            'is_default' => $validated['is_default'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment method created',
            'data' => $paymentMethod,
        ]);
    }

    /**
     * List payment methods
     */
    public function index()
    {
        $tenant = Auth::user()->tenant_id;
        
        $methods = PaymentMethod::where('tenant_id', $tenant)
            ->where('is_active', true)
            ->get(['method_id', 'method_name', 'method_type', 'is_default','is_active','created_at']);

        return response()->json([
            'success' => true,
            'data' => $methods,
        ]);
    }

    /**
     * Delete payment methods
     */
    public function destroy(string $id)
    {
        $tenant = Auth::user()->tenant_id;

        $method = PaymentMethod::where('tenant_id', $tenant)
            ->where('method_id', $id)
            ->first();

        if (!$method) {
            return response()->json([
                'success' => false,
                'message' => 'Payment method not found',
            ], 404);
        }

        $method->is_active = false;
        $method->save();

        return response()->json([
            'success' => true,
            'message' => 'Payment method deleted',
            'data' => $method,
        ]);
    }
    
    /**
     * Update payment methods
     */
    public function update(Request $request, string $id)
    {
        $tenant = Auth::user()->tenant_id;

        $method = PaymentMethod::where('tenant_id', $tenant)
            ->where('method_id', $id)
            ->first();

        if (!$method) {
            return response()->json([
                'success' => false,
                'message' => 'Payment method not found',
            ], 404);
        }

        $validated = $request->validate([
            'method_name' => 'nullable|string|max:100',
            'method_type' => 'nullable|in:credit_card,bank_transfer,cash,e_wallet',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        //Set default payment method if requested
        if ($validated['is_default'] ?? false) {
            PaymentMethod::where('tenant_id',Auth::user()->tenant_id)
            ->update([
                'is_default' => false
            ]);
        }

        //Check if method name already exists for tenant
        if (isset($validated['method_name'])) {
            $exists = PaymentMethod::where('tenant_id', Auth::user()->tenant_id)
                ->where('method_name', $validated['method_name'])
                ->where('method_id', '!=', $id)
                ->exists();                     

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method with this name already exists',
                ], 400);
            }
        }

        $method->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payment method updated',
            'data' => $method,
        ]);
    }
    
     /**
     * Toggle status of a payment method
     */
    public function toggleStatus(string $id)
    {
        $tenant = Auth::user()->tenant_id;
        $method = PaymentMethod::where('tenant_id', $tenant)
                ->where('method_id', $id)
                ->first();
        if (!$method) {
            return response()->json([
                'success' => false,
                'message' => 'Payment method not found',
            ], 404);
        }

        $method->is_active = !$method->is_active;
        $method->save();

        return response()->json([
            'success' => true,
            'message' => 'Payment method status updated',
            'data' => $method,
        ]);
    }
}
