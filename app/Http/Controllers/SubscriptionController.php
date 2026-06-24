<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Services\SubscriptionService;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    protected $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * API 1: LIST SUBSCRIPTIONS - បញ្ជីជាវ
     * 
     * GET /api/v1/subscriptions
     * 
     * For Admin: Get all subscriptions
     * For Tenant: Get own subscription history
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Subscription::with(['plan', 'tenant']);

        // If not admin, show only own subscriptions
        if (!$user->isAdmin()) {
            $query->where('tenant_id', $user->tenant_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by tenant (admin only)
        if ($user->isAdmin() && $request->has('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        $subscriptions = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return SubscriptionResource::collection($subscriptions);
    }

    /**
     * API 2: GET SUBSCRIPTION - ជាវមួយ
     * 
     * GET /api/v1/subscriptions/{id}
     */
    public function show(string $id, Request $request)
    {
        $user = $request->user();

        $query = Subscription::with(['plan.features', 'tenant', 'invoices']);

        // If not admin, ensure it's own subscription
        if (!$user->isAdmin()) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $subscription = $query->find($id);

        if (!$subscription){
            return response()->json([
                'success' => false,
                'message' => 'Subscription not found.'
            ], 404);
        }

        return new SubscriptionResource($subscription);
    }

    /**
     * API 3: SUBSCRIBE - ជាវថ្មី
     * 
     * POST /api/v1/subscriptions
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'required|uuid|exists:subscription_plans,plan_id',
            'billing_cycle' => 'required|in:monthly,yearly',
            'auto_renew' => 'boolean',
        ]);

        try {
            $tenant = app('tenant');

            $subscription = $this->subscriptionService->subscribe(
                tenantId: $tenant->tenant_id,
                planId: $validated['plan_id'],
                billingCycle: $validated['billing_cycle'],
                autoRenew: $validated['auto_renew'] ?? true
            );

            return response()->json([
                'success' => true,
                'message' => 'ជាវបានជោគជ័យ (Subscribed successfully)',
                'data' => new SubscriptionResource($subscription),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 4: UPDATE SUBSCRIPTION - កែប្រែ
     * 
     * PUT /api/v1/subscriptions/{id}
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'auto_renew' => 'boolean',
        ]);

        $tenant = app('tenant');

        $subscription = Subscription::with('plan.features','tenant')
            ->where('subscription_id', $id)
            ->where('tenant_id', $tenant->tenant_id)
            ->first();

        if (!$subscription){
            return response()->json([
                'success' => false,
                'message' => 'Subscription not found.',
            ], 404);
        }
        $subscription->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Subscription updated successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * API 5: UPGRADE - ដំឡើង
     * 
     * POST /api/v1/subscriptions/{id}/upgrade
     */
    public function upgrade(Request $request, string $id)
    {
        $validated = $request->validate([
            'plan_id' => 'required|uuid|exists:subscription_plans,plan_id',
        ]);

        try {
            $upgraded = $this->subscriptionService->upgrade(
                subscriptionId: $id,
                newPlanId: $validated['plan_id']
            );

            return response()->json([
                'success' => true,
                'message' => 'ដំឡើងបានជោគជ័យ (Upgraded successfully)',
                'data' => new SubscriptionResource($upgraded),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 6: DOWNGRADE - បន្ទាប
     * 
     * POST /api/v1/subscriptions/{id}/downgrade
     */
    public function downgrade(Request $request, string $id)
    {
        $validated = $request->validate([
            'plan_id' => 'required|uuid|exists:subscription_plans,plan_id',
        ]);

        try {
            $downgraded = $this->subscriptionService->downgrade(
                subscriptionId: $id,
                newPlanId: $validated['plan_id']
            );

            return response()->json([
                'success' => true,
                'message' => 'បន្ទាបបានជោគជ័យ (Downgraded successfully)',
                'data' => new SubscriptionResource($downgraded),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 7: CANCEL - លុបចោល
     * 
     * POST /api/v1/subscriptions/{id}/cancel
     */
    public function cancel(Request $request, string $id)
    {
        $validated = $request->validate([
            'immediately' => 'boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $cancelled = $this->subscriptionService->cancel(
                subscriptionId: $id,
                immediately: $validated['immediately'] ?? false
            );

            return response()->json([
                'success' => true,
                'message' => 'លុបចោលបានជោគជ័យ (Cancelled successfully)',
                'data' => new SubscriptionResource($cancelled),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 8: REACTIVATE - ធ្វើឱ្យសកម្មវិញ
     * 
     * POST /api/v1/subscriptions/{id}/reactivate
     */
    public function reactivate(string $id)
    {

        $subscription = Subscription::with(['plan.features','tenant'])
            ->where('subscription_id', $id)
            ->where('status', Subscription::STATUS_CANCELLED)
            ->first();

        if (!$subscription) {
        return response()->json([
            'success' => false,
            'message' => 'Cancelled subscription not found.',
            'data' => [
                'subscription_id' => $id,
                'status' => Subscription::STATUS_CANCELLED
            ]
        ], 404);
    }

        // If expired extend subscription
        if ($subscription->end_date->isPast()) {
            $subscription->update([
                'start_date' => now(),
                'end_date' => now()->addMonth(), // Extend 1 month
            ]);
        }

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'auto_renew' => true,
        ]);       

        // Reactivate tenant
        $subscription->tenant->update([
            'status' => 'active'
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Subscription reactivated successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * API 9: GET CURRENT - ជាវបច្ចុប្បន្ន
     * 
     * GET /api/v1/subscriptions/current
     */
    public function current()
    {
        $tenant = app('tenant');

        $subscription = Subscription::with(['plan.features','tenant'])
            ->where('tenant_id', $tenant->tenant_id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription',
                'has_subscription' => false,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * API 10: LIST AVAILABLE PLANS - គម្រោងដែលអាចជាវបាន
     * 
     * GET /api/v1/subscriptions/plans
     */
    public function listPlans()
    {
        $plans = SubscriptionPlan::with('features')
            ->where('is_active', true)
            ->orderBy('monthly_price', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }
}