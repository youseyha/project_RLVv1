<?php

namespace App\Jobs;

use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSubscriptionDowngrade implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $subscriptions = Subscription::whereNotNull('pending_plan_id')
            ->whereNotNull('change_plan_at')
            ->where('change_plan_at', '<=', now())
            ->get();

        foreach ($subscriptions as $subscription) {

            DB::transaction(function () use ($subscription) {

                $oldPlanId = $subscription->current_plan_id;

                $subscription->update([
                    'plan_id' => $subscription->pending_plan_id,
                    'current_plan_id' => $subscription->pending_plan_id,
                    'pending_plan_id' => null,
                    'change_plan_at' => null,
                ]);

                Log::info('Subscription downgraded automatically', [
                    'subscription_id' => $subscription->subscription_id,
                    'old_plan_id' => $oldPlanId,
                    'new_plan_id' => $subscription->current_plan_id,
                ]);
            });
        }
    }
}