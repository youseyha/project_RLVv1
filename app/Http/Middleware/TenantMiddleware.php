<?php

namespace App\Http\Middleware;

use App\Models\Tenants;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Cache;

class TenantMiddleware
{
    /**
     * 
     * Purpose: Validate tenant exists and is active
     */
    public function handle(Request $request, Closure $next): Response
    {
        // CHECK USER AUTHENTICATED
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // SKIP FOR SUPER ADMIN (no tenant required)
        if ($user->hasRole('super_admin')) {
            return $next($request);
        }

        // CHECK USER HAS TENANT
        if (!$user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'No tenant associated with this user.',
            ], 403);
        }

        // LOAD TENANT (with cache)
        $tenant = Cache::remember(
            "tenant:{$user->tenant_id}",
            3600, // 1 hour
            fn() => Tenants::find($user->tenant_id) 
        );

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found.',
            ], 404);
        }

        if ($tenant->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => match($tenant->status) {
                    'suspended' => 'Your tenant account is suspended. Please contact support.',
                    'terminated' => 'Your tenant account is terminated.',
                    default => 'Your tenant account is not active.',
                },
                'status' => $tenant->status,
            ], 403);
        }

        // CHECK USER STATUS
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'User account deactivated.',
            ], 403);
        }

        // BIND TENANT TO REQUEST & CONTAINER
        $request->merge(['tenant' => $tenant]);
        app()->instance('tenant', $tenant);

        return $next($request);
    }
}