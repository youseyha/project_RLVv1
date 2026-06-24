<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasUuids;
    
    protected $table = 'subscriptions';
    protected $primaryKey = 'subscription_id';
    public $incrementing = false;
    protected $keyType = 'string';

    // Status Constants តាម ERD
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'current_plan_id',
        'pending_plan_id',
        'change_plan_at',
        'start_date',
        'end_date',
        'next_billing_date',
        'status',
        'auto_renew',
    ];
    
    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'next_billing_date' => 'datetime',
        'change_plan_at' => 'datetime',
        'auto_renew' => 'boolean',
    ];
    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenants::class, 'tenant_id', 'tenant_id');
    }
    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id', 'plan_id');
    }
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'subscription_id', 'subscription_id');
    }
    public function currentPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class,'current_plan_id','plan_id');
    }
    public function pendingPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class,'pending_plan_id','plan_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
    
    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED)
                     ->orWhere('end_date', '<', now());
    }
    
    public function scopeDueForRenewal($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
                     ->where('auto_renew', true)
                     ->where('next_billing_date', '<=', now()->addDays(7));
    }
    
    // ACCESSORS & HELPERS
   public function getIsActiveAttribute(): bool
    {
        return $this->status === self::STATUS_ACTIVE 
            && $this->end_date->isFuture();
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->end_date->isPast();
    }

    public function getDaysRemainingAttribute(): int
    {
        if ($this->is_expired) {
            return 0;
        }
        return now()->diffInDays($this->end_date);
    }
    
    /**
     * Check if subscription has specific feature
     */
    public function hasFeature(string $featureCode): bool
    {
        return $this->plan->features()
            ->where('feature_code', $featureCode)
            ->where('is_enabled', true)
            ->exists();
    }
    /**
     * Get feature limit value
     */
    public function getFeatureLimit(string $featureCode): ?int
    {
        $feature = $this->plan->features()
            ->where('feature_code', $featureCode)
            ->first();

        return $feature?->limit_value;
    }

    /**
     * Check if within limits
     */
    public function isWithinLimit(string $limitType, int $currentCount): bool
    {
        $limit = match($limitType) {
            'branches' => $this->plan->max_branches,
            'users' => $this->plan->max_users,
            'terminals' => $this->plan->max_pos_terminals,
            'transactions' => $this->plan->transaction_limit_monthly,
            default => null,
        };

        if ($limit === null || $limit === 0) {
            return true; // Unlimited
        }

        return $currentCount < $limit;
    }
}
