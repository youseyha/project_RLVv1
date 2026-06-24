<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Super Admin bypass
        if ($user && $user->hasRole('super_admin')) {
            return $next($request);
        }

        $tenant = app('tenant');

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found.',
            ], 404);
        }

        // Latest subscription
        $subscription = $tenant->subscriptions()
            ->with('plan')
            ->latest('created_at')
            ->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No subscription found.',
                'requires_subscription' => true,
            ], 403);
        }

        switch ($subscription->status) {

            case Subscription::STATUS_PENDING:
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription is pending payment.',
                    'subscription_status' => 'pending',
                    'invoice_required' => true,
                ], 403);

            case Subscription::STATUS_SUSPENDED:
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription suspended. Please pay outstanding invoice.',
                    'subscription_status' => 'suspended',
                ], 403);

            case Subscription::STATUS_CANCELLED:
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription cancelled.',
                    'subscription_status' => 'cancelled',
                ], 403);

            case Subscription::STATUS_EXPIRED:
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription expired.',
                    'subscription_status' => 'expired',
                ], 403);

            case Subscription::STATUS_ACTIVE:

                if ($subscription->end_date < now()) {

                    $subscription->update([
                        'status' => Subscription::STATUS_EXPIRED,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Subscription expired.',
                        'subscription_status' => 'expired',
                    ], 403);
                }

                break;
        }

        $request->attributes->set('subscription', $subscription);

        app()->instance('subscription', $subscription);

        return $next($request);
    }
}