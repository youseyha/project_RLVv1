<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Spatie\Permission\PermissionRegistrar;

class SetSpatieTeamId
{
    /**
     * 
     * Purpose: Set tenant context for Spatie Permission
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant_id) {
            // Set tenant context for Spatie Permission
            app(PermissionRegistrar::class)
                ->setPermissionsTeamId($user->tenant_id);
        }

        return $next($request);
    }
}