<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'subscription_id' => $this->subscription_id,
            
            // Plan Info
            'plan' => $this->when(
                $this->relationLoaded('plan'),
                fn() => new SubscriptionPlanResource($this->plan)
            ),
            
            // Tenant Info (admin only)
            'tenant' => $this->when(
                $this->relationLoaded('tenant'),
                fn() => [
                    'tenant_id' => $this->tenant->tenant_id,
                    'company_name' => $this->tenant->company_name,
                    'email' => $this->tenant->email,
                ]
            ),
            
            // Dates តាម ERD
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date->format('Y-m-d'),
            'next_billing_date' => $this->next_billing_date->format('Y-m-d'),
            
            // Status តាម ERD
            'status' => $this->status,
            'is_active' => $this->is_active,
            'is_expired' => $this->is_expired,
            'days_remaining' => $this->days_remaining,
            
            // Settings
            'auto_renew' => (bool) $this->auto_renew,
            
            // Timestamps
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}