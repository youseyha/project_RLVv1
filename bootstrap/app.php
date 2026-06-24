<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        
        $middleware->alias([
             // Custom Middleware
            'tenant' => \App\Http\Middleware\TenantMiddleware::class,
            'subscription' => \App\Http\Middleware\SubscriptionMiddleware::class,
            'feature' => \App\Http\Middleware\FeatureMiddleware::class,
            'limit' => \App\Http\Middleware\CheckResourceLimit::class,
            'branch' => \App\Http\Middleware\BranchMiddleware::class,
            'spatie.team' => \App\Http\Middleware\SetSpatieTeamId::class, 
            'verify.webhook' => \App\Http\Middleware\VerifyWebhookSignature::class,

            // Spatie Permission Middleware
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->group('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();