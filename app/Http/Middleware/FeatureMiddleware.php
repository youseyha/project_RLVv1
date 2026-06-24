<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FeatureMiddleware
{
    /**
     * 
     * Purpose: Check if tenant's plan has specific feature
     * 
     * Usage: Route::middleware('feature:advanced_reporting')
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        // Skip for super admin
        if ($user->hasRole('super_admin')) {
            return $next($request);
        }

        // Get subscription
        $subscription = app('subscription');

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription.',
            ], 403);
        }

        // Check if plan has feature
        $hasFeature = $subscription->plan->features()
            ->where('feature_key', $feature)
            ->where('is_enabled', true)
            ->exists();

        if (!$hasFeature) {
            return response()->json([
                'success' => false,
                'message' => "Feature '{$feature}' not available in your plan.",
                'feature_required' => $feature,
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}