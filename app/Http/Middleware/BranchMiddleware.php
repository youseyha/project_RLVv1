<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BranchMiddleware
{
    /**
     * 
     * Purpose: Check user has branch assignment
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip for super admin and admin
        if ($user->hasRole(['super_admin', 'admin'])) {
            return $next($request);
        }

        // Check user has branch
        if (!$user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'No branch assigned to user.',
            ], 403);
        }

        // Load branch if not loaded
        if (!$user->relationLoaded('branch')) {
            $user->load('branch');
        }

        if (!$user->branch) {
            return response()->json([
                'success' => false,
                'message' => 'Branch not found.',
            ], 404);
        }

        // Bind branch to request
        $request->merge(['branch' => $user->branch]);
        app()->instance('branch', $user->branch);

        return $next($request);
    }
}