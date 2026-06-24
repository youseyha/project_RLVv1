<?php

namespace App\Services;

use App\Models\Branches;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\Invoice;
use App\Models\PosTerminal;
use App\Models\Tenants;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SubscriptionService
{
    /**
     * SUBSCRIBE - ជាវសេវា
     * 
     * @param string $tenantId
     * @param string $planId
     * @param string $billingCycle ('monthly' | 'yearly')
     * @param bool $autoRenew
     * @return Subscription
     */
    public function subscribe(
        string $tenantId,
        string $planId,
        string $billingCycle = 'monthly',
        bool $autoRenew = true
    ): Subscription {
        return DB::transaction(function () use ($tenantId, $planId, $billingCycle, $autoRenew) {
            
            Log::info("Creating subscription", [
                'tenant_id' => $tenantId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
            ]);

            // ពិនិត្យមើលគម្រោង
            $plan = SubscriptionPlan::where('plan_id', $planId)
                ->where('is_active', true)
                ->firstOrFail();

            // ពិនិត្យមើល subscription ដែលមានស្រាប់
            $existingSubscription = Subscription::where('tenant_id', $tenantId)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->first();

            if ($existingSubscription) {
                throw new \Exception('Tenant already has an active subscription');
            }

            // គណនាកាលបរិច្ឆេទ
            $startDate = now();
            $endDate = $billingCycle === 'yearly' 
                ? $startDate->copy()->addYear() 
                : $startDate->copy()->addMonth();
            $nextBillingDate = $endDate->copy();

            // គណនាតម្លៃ
            $amount = $billingCycle === 'yearly' 
                ? $plan->yearly_price 
                : $plan->monthly_price;

            // បង្កើត Subscription
            $subscription = Subscription::create([
                'tenant_id' => $tenantId,
                'plan_id' => $planId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'next_billing_date' => $nextBillingDate,
                'status' => Subscription::STATUS_PENDING,
                'auto_renew' => $autoRenew,
            ]);

            // បង្កើត Invoice
            $this->createInvoice(
                subscription: $subscription,
                amount: $amount,
                billingCycle: $billingCycle
            );

            // Update tenant status
            Tenants::where('tenant_id', $tenantId)->update([
                'status' => 'active',
            ]);

            Log::info("Subscription created successfully", [
                'subscription_id' => $subscription->subscription_id,
            ]);

            return $subscription->load(['plan.features','tenant']);
        });
    }

    /**
     * UPGRADE - ដំឡើង
     * 
     * @param string $subscriptionId
     * @param string $newPlanId
     * @return Subscription
     */
    public function upgrade(string $subscriptionId, string $newPlanId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $newPlanId) {
            
            Log::info("Upgrading subscription", [
                'subscription_id' => $subscriptionId,
                'new_plan_id' => $newPlanId,
            ]);

            // ទាញយក subscription បច្ចុប្បន្ន
            $subscription = Subscription::with('plan')
                ->where('subscription_id', $subscriptionId)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->firstOrFail();

            // ទាញយកគម្រោងថ្មី
            $newPlan = SubscriptionPlan::where('plan_id', $newPlanId)
                ->where('is_active', true)
                ->firstOrFail();

            // ពិនិត្យមើលថាពិតជា upgrade
            if ($newPlan->monthly_price <= $subscription->plan->monthly_price) {
                throw new \Exception('New plan must be higher tier for upgrade');
            }

            // គណនា prorated amount
            $daysRemaining = now()->diffInDays($subscription->end_date);
            $totalDays = $subscription->start_date->diffInDays($subscription->end_date);
            $unusedAmount = ($subscription->plan->monthly_price / $totalDays) * $daysRemaining;
            $newAmount = ($newPlan->monthly_price / $totalDays) * $daysRemaining;
            $proratedAmount = $newAmount - $unusedAmount;

            // Update subscription
            $subscription->update([
                'plan_id' => $newPlanId,
                'current_plan_id' => $newPlanId,
            ]);

            // បង្កើត Invoice សម្រាប់ prorated amount
            if ($proratedAmount > 0) {
                $this->createInvoice(
                    subscription: $subscription,
                    amount: $proratedAmount,
                    billingCycle: 'prorated',
                    description: "Prorated upgrade from {$subscription->plan->plan_name} to {$newPlan->plan_name}"
                );
            }

            Log::info("Subscription upgraded successfully", [
                'subscription_id' => $subscription->subscription_id,
                'prorated_amount' => $proratedAmount,
            ]);

            return $subscription->fresh()->load(['plan','tenant']);
        });
    }

    /**
     * DOWNGRADE - បន្ទាប
     * 
     * @param string $subscriptionId
     * @param string $newPlanId
     * @return Subscription
     */
    public function downgrade(string $subscriptionId, string $newPlanId): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $newPlanId) {
            
            Log::info("Downgrading subscription", [
                'subscription_id' => $subscriptionId,
                'new_plan_id' => $newPlanId,
            ]);

            // ① ទាញយក subscription
            $subscription = Subscription::with('plan')
                ->where('subscription_id', $subscriptionId)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->firstOrFail();

            // ② ទាញយកគម្រោងថ្មី
            $newPlan = SubscriptionPlan::where('plan_id', $newPlanId)
                ->where('is_active', true)
                ->firstOrFail();

            // ③ ពិនិត្យមើលថាពិតជា downgrade
            if ($newPlan->monthly_price >= $subscription->plan->monthly_price) {
                throw new \Exception('New plan must be lower tier for downgrade');
            }

            // ④ ពិនិត្យកម្រិត (Check if current usage fits new plan)
            $this->validateDowngrade($subscription->tenant_id, $newPlan);

            // ⑤ Schedule downgrade (ចូលជាធរមានពេលបន្ត)
            // Downgrade will apply at end of current billing period
            $subscription->update([
                'pending_plan_id' => $newPlanId,
                'change_plan_at' => $subscription->end_date,
            ]);

            Log::info("Subscription downgraded successfully");

            return $subscription->fresh()->load(['plan','tenant']);
        });
    }

    /**
     * CANCEL - លុបចោល
     * 
     * @param string $subscriptionId
     * @param bool $immediately (true = cancel now, false = at end of period)
     * @return Subscription
     */
    public function cancel(string $subscriptionId, bool $immediately = false): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $immediately) {
            
            Log::info("Cancelling subscription", [
                'subscription_id' => $subscriptionId,
                'immediately' => $immediately,
            ]);

            $subscription = Subscription::findOrFail($subscriptionId);

            if ($immediately) {
                // Cancel immediately
                $subscription->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'end_date' => now(),
                    'auto_renew' => false,
                ]);

                // Update tenant status
                Tenants::where('tenant_id', $subscription->tenant_id)
                    ->update(['status' => 'suspended']);

                Log::info("Subscription cancelled immediately");

            } else {
                // Cancel at end of period
                $subscription->update([
                    'auto_renew' => false,
                ]);

                Log::info("Subscription will cancel at end of period", [
                    'end_date' => $subscription->end_date,
                ]);
            }

            return $subscription->fresh()->load(['plan.features','tenant']);
        });
    }

    /**
     * CHECK LIMITS - ពិនិត្យកម្រិត
     * 
     * @param string $tenantId
     * @param string $limitType (branches, users, terminals, transactions)
     * @return array
     */
    public function checkLimits(string $tenantId, string $limitType): array
    {
        // ទាញយក subscription
        $subscription = Subscription::with('plan')
            ->where('tenant_id', $tenantId)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        if (!$subscription) {
            return [
                'has_subscription' => false,
                'within_limit' => false,
                'message' => 'No active subscription',
            ];
        }

        // ទាញយកចំនួនបច្ចុប្បន្ន
        $currentCount = match($limitType) {
            'branches' => Branches::where('tenant_id', $tenantId)->count(),
            'users' => User::where('tenant_id', $tenantId)->count(),
            'terminals' => PosTerminal::whereHas('branch', function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })->count(),
            'transactions' => Transaction::whereHas('branch', function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->whereMonth('transaction_date', now()->month)
            ->count(),
            default => 0,
        };

        // ទាញយកកម្រិត
        $limit = match($limitType) {
            'branches' => $subscription->plan->max_branches,
            'users' => $subscription->plan->max_users,
            'terminals' => $subscription->plan->max_pos_terminals,
            'transactions' => $subscription->plan->transaction_limit_monthly,
            default => 0,
        };

        // ពិនិត្យ
        $withinLimit = $limit === 0 || $currentCount < $limit; // 0 = unlimited

        return [
            'has_subscription' => true,
            'within_limit' => $withinLimit,
            'current_count' => $currentCount,
            'limit' => $limit,
            'limit_type' => $limitType,
            'percentage_used' => $limit > 0 ? ($currentCount / $limit) * 100 : 0,
            'remaining' => $limit > 0 ? $limit - $currentCount : 'unlimited',
        ];
    }

    /**
     * HELPER METHODS
     */
    
    /**
     * Create invoice
     */
    protected function createInvoice(
        Subscription $subscription,
        float $amount,
        string $billingCycle,
        ?string $description = null
    ): Invoice {
        $invoiceNumber = $this->generateInvoiceNumber();

        $invoice = Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->subscription_id,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => now(),
            'due_date' => now()->addDays(7),
            'subtotal' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'amount_paid' => 0,
            'amount_due' => $amount,
            'status' => 'pending',
        ]);

        // Add invoice items
        $invoice->items()->create([
            'invoice_id' => $invoice->invoice_id,
            'description' => $description ?? "Subscription fee for {$subscription->plan->plan_name} ({$billingCycle})",
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
            'period_start' => $subscription->start_date,
            'period_end' => $subscription->end_date,
        ]);

        return $invoice;
    }

    /**
     * Validate downgrade
     */
    protected function validateDowngrade(string $tenantId, SubscriptionPlan $newPlan): void
    {
        $checks = [
            'branches' => Branches::where('tenant_id', $tenantId)->count(),
            'users' => User::where('tenant_id', $tenantId)->count(),
            'terminals' => PosTerminal::whereHas('branch', function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })->count(),
        ];

        $limits = [
            'branches' => $newPlan->max_branches,
            'users' => $newPlan->max_users,
            'terminals' => $newPlan->max_pos_terminals,
        ];

        foreach ($checks as $type => $count) {
            $limit = $limits[$type];
            if ($limit > 0 && $count > $limit) {
                throw new \Exception(
                    "Cannot downgrade: Current {$type} count ({$count}) exceeds new plan limit ({$limit})"
                );
            }
        }
    }

    /**
     * Generate invoice number
     */
    protected function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = now()->format('Ymd');
        $random = strtoupper(\Illuminate\Support\Str::random(6));
        
        return "{$prefix}-{$date}-{$random}";
    }
}