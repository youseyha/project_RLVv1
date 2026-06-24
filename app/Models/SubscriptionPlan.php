<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasUuids;

    protected $table = 'subscription_plans';
    protected $primaryKey = 'plan_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'plan_name',
        'description',
        'monthly_price',
        'yearly_price',
        'max_branches',
        'max_users',
        'max_pos_terminals',
        'transaction_limit_monthly',
        'has_analytics',
        'has_api_access',
        'is_active',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'max_branches' => 'integer',
        'max_users' => 'integer',
        'max_pos_terminals' => 'integer',
        'transaction_limit_monthly' => 'integer',
        'has_analytics' => 'boolean',
        'has_api_access' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];
    // Relationships
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id', 'plan_id');
    }
    
    public function features()
    {
        return $this->hasMany(PlanFeature::class, 'plan_id', 'plan_id');
    }
     // SCOPES
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ACCESSORS
    
    public function getYearlySavingsAttribute(): float
    {
        $monthlyTotal = $this->monthly_price * 12;
        return $monthlyTotal - $this->yearly_price;
    }

    public function getYearlySavingsPercentageAttribute(): float
    {
        $monthlyTotal = $this->monthly_price * 12;
        return $monthlyTotal > 0 
            ? (($monthlyTotal - $this->yearly_price) / $monthlyTotal) * 100 
            : 0;
    }
    /**
     * Get limit for specific resource
     */
    public function getLimit(string $resource): ?int
    {
        $limitKey = match($resource) {
            'users' => 'max_users',
            'branches' => 'max_branches',
            'pos_terminals' => 'max_pos_terminals',
            'transactions' => 'transaction_limit_monthly',
            'products' => 'max_products',
            default => null,
        };

        if (!$limitKey || !$this->limits) {
            return null;
        }

        return $this->limits[$limitKey] ?? null;
    }

    /**
     * Check if resource has limit
     */
    public function hasLimit(string $resource): bool
    {
        $limit = $this->getLimit($resource);
        return $limit !== null && $limit !== -1;
    }

    /**
     * Check if plan has specific feature
     */
    public function hasFeature(string $featureCode): bool
    {
        return $this->features()
            ->where('feature_code', $featureCode)
            ->where('is_enabled', true)
            ->exists();
    }
}
