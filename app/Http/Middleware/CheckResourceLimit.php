<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckResourceLimit
{
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $user = $request->user();

        // Skip for super admin
        if ($user && $user->hasRole('super_admin')) {
            return $next($request);
        }

        // Get subscription from container
        $subscription = app()->has('subscription') ? app('subscription') : null;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription found.',
            ], 403);
        }

        $tenant = app('tenant');
        
        // CRITICAL: Access plan relationship
        if (!$subscription->relationLoaded('plan')) {
            $subscription->load('plan');
        }

        $plan = $subscription->plan;

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found.',
            ], 500);
        }

        // CRITICAL: Access limits as ATTRIBUTE (not method)
        $limits = $plan->limits; // NO parentheses!

        // Debug if needed
        // \Log::info('Plan limits', ['limits' => $limits, 'type' => gettype($limits)]);

        if (!$limits || !is_array($limits)) {
            // No limits = unlimited
            return $next($request);
        }

        // Map resource to limit key
        $limitKey = match($resource) {
            'users' => 'max_users',
            'branches' => 'max_branches',
            'pos_terminals' => 'max_pos_terminals',
            'transactions' => 'transaction_limit_monthly',
            'products' => 'max_products',
            default => null,
        };

        if (!$limitKey) {
            return $next($request);
        }

        // Get limit value from array
        $limitValue = $limits[$limitKey] ?? null;

        if ($limitValue === null || $limitValue === -1 || $limitValue === 0) {
            // Unlimited
            return $next($request);
        }

        // Count current usage
        $currentUsage = match($resource) {
            'users' => $tenant->users()->count(),
            'branches' => $tenant->branches()->count(),
            'pos_terminals' => DB::table('pos_terminals')
                ->where('tenant_id', $tenant->tenant_id)
                ->count(),
            'products' => $tenant->products()->count(),
            'transactions' => $tenant->transactions()
                ->whereMonth('created_at', now()->month)
                ->count(),
            default => 0,
        };

        // Check limit
        if ($currentUsage >= $limitValue) {
            return response()->json([
                'success' => false,
                'message' => "Resource limit reached for '{$resource}'.",
                'error_code' => 'LIMIT_REACHED',
                'details' => [
                    'resource' => $resource,
                    'current_usage' => $currentUsage,
                    'limit' => $limitValue,
                    'upgrade_required' => true,
                ],
            ], 403);
        }

        return $next($request);
    }
}