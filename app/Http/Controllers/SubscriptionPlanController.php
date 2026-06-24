<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use App\Models\PlanFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanController extends Controller
{
    /**
     * API 1: LIST ALL PLANS - បញ្ជីគម្រោងទាំងអស់
     * 
     * GET /api/v1/admin/plans
     * 
     * Query Parameters:
     * - is_active: Filter by status (true/false)
     * - sort_by: Sort field (price, name)
     */
    public function index(Request $request)
    {
        $query = SubscriptionPlan::with(['features', 'subscriptions']);

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sort
        $sortBy = $request->get('sort_by', 'monthly_price');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $plans = $query->get();

        return SubscriptionPlanResource::collection($plans);
    }

    /**
     * API 2: GET SINGLE PLAN - គម្រោងមួយ
     * 
     * GET /api/v1/admin/plans/{id}
     */
    public function show(string $id)
    {
        $plan = SubscriptionPlan::with(['features', 'subscriptions'])
            ->findOrFail($id);

        return new SubscriptionPlanResource($plan);
    }

    /**
     * API 3: CREATE PLAN - បង្កើតគម្រោង
     * 
     * POST /api/v1/admin/plans
     * 
     * Body:
     * {
     *   "plan_name": "Premium Plan",
     *   "description": "Full features",
     *   "monthly_price": 49.99,
     *   "yearly_price": 499.99,
     *   "max_branches": 10,
     *   "max_users": 50,
     *   "max_pos_terminals": 20,
     *   "has_analytics": true,
     *   "has_api_access": true,
     *   "transaction_limit_monthly": 10000,
     *   "is_active": true,
     *   "features": [
     *     {
     *       "feature_name": "Advanced Reports",
     *       "feature_code": "advanced_reports",
     *       "is_enabled": true,
     *       "description": "Generate advanced analytics reports"
     *     }
     *   ]
     * }
     */
    public function store(CreatePlanRequest $request)
    {
        DB::beginTransaction();

        try {
            // ① បង្កើត Plan
            $plan = SubscriptionPlan::create($request->validated());

            // ② បង្កើត Features (if provided)
            if ($request->has('features')) {
                foreach ($request->features as $feature) {
                    PlanFeature::create([
                        'plan_id' => $plan->plan_id,
                        'feature_name' => $feature['feature_name'],
                        'feature_code' => $feature['feature_code'],
                        'is_enabled' => $feature['is_enabled'] ?? true,
                        'description' => $feature['description'] ?? null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Plan created successfully',
                'data' => new SubscriptionPlanResource($plan->load('features')),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create plan: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 4: UPDATE PLAN - កែប្រែគម្រោង
     * 
     * PUT /api/v1/admin/plans/{id}
     */
    public function update(UpdatePlanRequest $request, string $id)
    {
        DB::beginTransaction();

        try {
            $plan = SubscriptionPlan::findOrFail($id);

            // Update plan
            $plan->update($request->validated());

            // Update features if provided
            if ($request->has('features')) {
                // Delete existing features
                $plan->features()->delete();

                // Create new features
                foreach ($request->features as $feature) {
                    PlanFeature::create([
                        'plan_id' => $plan->plan_id,
                        'feature_name' => $feature['feature_name'],
                        'feature_code' => $feature['feature_code'],
                        'is_enabled' => $feature['is_enabled'] ?? true,
                        'description' => $feature['description'] ?? null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Plan updated successfully',
                'data' => new SubscriptionPlanResource($plan->load('features')),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update plan: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 5: DELETE PLAN - លុបគម្រោង
     * 
     * DELETE /api/v1/admin/plans/{id}
     * 
     * Note: Cannot delete if plan has active subscriptions
     */
    public function destroy(string $id)
    {
        $plan = SubscriptionPlan::with('subscriptions')->findOrFail($id);

        // Check if plan has active subscriptions
        $activeSubscriptions = $plan->subscriptions()
            ->where('status', 'active')
            ->count();

        if ($activeSubscriptions > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete plan with {$activeSubscriptions} active subscriptions",
                'active_subscriptions' => $activeSubscriptions,
            ], 400);
        }

        // Soft delete or deactivate
        $plan->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Plan deactivated successfully',
        ]);
    }

    /**
     * API 6: ACTIVATE/DEACTIVATE PLAN - ធ្វើឱ្យសកម្ម/អសកម្ម
     * 
     * PATCH /api/v1/admin/plans/{id}/toggle-status
     */
    public function toggleStatus(string $id)
    {
        $plan = SubscriptionPlan::with('subscriptions')->findOrFail($id);

        $plan->update([
            'is_active' => !$plan->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => $plan->is_active ? 'Plan activated' : 'Plan deactivated',
            'data' => new SubscriptionPlanResource($plan),
        ]);
    }

    /**
     * API 7: GET PLAN STATISTICS - ស្ថិតិគម្រោង
     * 
     * GET /api/v1/admin/plans/{id}/statistics
     */
    public function statistics(string $id)
    {
        $plan = SubscriptionPlan::with('subscriptions')->findOrFail($id);

        $stats = [
            'total_subscriptions' => $plan->subscriptions()->count(),
            'active_subscriptions' => $plan->subscriptions()->where('status', 'active')->count(),
            'cancelled_subscriptions' => $plan->subscriptions()->where('status', 'cancelled')->count(),
            'expired_subscriptions' => $plan->subscriptions()->where('status', 'expired')->count(),
            'monthly_revenue' => $plan->subscriptions()
                ->where('status', 'active')
                ->count() * $plan->monthly_price,
            'recent_subscriptions' => $plan->subscriptions()
                ->latest()
                ->take(5)
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}