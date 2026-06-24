<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'plan_id' => $this->plan_id,
            'plan_name' => $this->plan_name,
            'description' => $this->description,
            
            // Pricing
            'monthly_price' => (float) $this->monthly_price,
            'yearly_price' => (float) $this->yearly_price,
            'yearly_savings' => (float) $this->yearly_savings,
            'yearly_savings_percentage' => (float) $this->yearly_savings_percentage,
            'has_analytics' => (bool) $this->has_analytics,
            'has_api_access' => (bool) $this->has_api_access,
            
            // Limits តាម ERD
            'limits' => [
                'max_branches' => $this->max_branches,
                'max_users' => $this->max_users,
                'max_pos_terminals' => $this->max_pos_terminals,
                'transaction_limit_monthly' => $this->transaction_limit_monthly,
            ],
            
            // Features តាម ERD
            'features' =>  $this->when($this->relationLoaded('features'),fn() => $this->features->map(fn($f) => [
                    'feature_id' => $f->feature_id,
                    'feature_name' => $f->feature_name,
                    'feature_code' => $f->feature_code,
                    'is_enabled' => $f->is_enabled,
                    'description' => $f->description,
                ])
            ),
            
            // Status
            'is_active' => (bool) $this->is_active,
            
            // Statistics (if loaded)
            'subscription_count' => $this->when(
                $this->relationLoaded('subscriptions'),
                fn() => $this->subscriptions->count()
            ),
            
            // Timestamps
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}