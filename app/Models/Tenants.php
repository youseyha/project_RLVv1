<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Tenants extends Model
{
    use HasUuids;    

    protected $primaryKey = 'tenant_id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        "company_name",
        "busines_type",
        "email",
        "phone",
        "address",
        "url_logo",
        "status"
    ];
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    // Tenant has many Branches
    public function users()
    {
        return $this->hasMany(User::class, 'tenant_id', 'tenant_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'tenant_id', 'tenant_id');
    }
    public function branches()
    {
        return $this->hasMany(Branches::class, 'tenant_id', 'tenant_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'tenant_id', 'tenant_id');
    }
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'tenant_id', 'tenant_id');
    }
    /**
     * Get active subscription
     */
    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class, 'tenant_id', 'tenant_id')
            ->where('status', 'active')
            ->where('end_date', '>=', now())
            ->latest();
    }
    public function subscription()
    {
        return $this->hasOne(
            Subscription::class,
            'tenant_id',
            'tenant_id'
        )->where('status', 'active')
         ->latest();
    }
    public function dailyReport(){
        return $this->hasMany(DailyReport::class,"tenant_id");
    }

    // ពិនិត្យ Tenant Status
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }
    public function getIsSuspendedAttribute(): bool
    {
        return $this->status === 'suspended';
    }
    public function getIsTerminatedAttribute(): bool
    {
        return $this->status === 'terminated';
    }
    
    //Method
    // Suspend Tenant
    public function suspend(string $reason): void
    {
        $this->update(['status' => 'suspended']);

        // Clear cache
        $this->clearCache();

        // Log
        Log::warning('Tenant suspended:'.$this->tenant_id, [
            'reason' => $reason,
        ]);
    }
    // Activate Tenant
    public function activate(): void
    {
        $this->update(['status' => 'active']);
        $this->clearCache();
    }
    // Terminate Tenant
    public function terminate(): void
    {
        $this->update(['status' => 'terminated']);
        $this->clearCache();

        // Revoke all user tokens
        foreach ($this->users as $user) {
            $user->tokens()->delete();
        }
    }
    // Check Subscription
    public function hasActiveSubscription(): bool
    {
        return $this->subscription()->exists();
    }
    // Check Branch Limit
    public function canAddBranch(): bool
    {
        $maxBranches = $this->subscription?->plan?->max_branches ?? 0;
        $currentBranches = $this->branches()->count();
        return $currentBranches < $maxBranches;
    }
    // Check User Limit
    public function canAddUser(): bool
    {
        $maxUsers = $this->subscription?->plan?->max_users ?? 0;
        $currentUsers = $this->users()->count();
        return $currentUsers < $maxUsers;
    }
    // Clear Cache
    public function clearCache(): void
    {
        Cache::forget("tenant:{$this->tenant_id}");
        Cache::forget("tenant:{$this->tenant_id}:subscription");
        Cache::tags(["tenant:{$this->tenant_id}"])->flush();
    }

    // ========================================
    // EVENTS
    // ========================================

    protected static function boot()
    {
        parent::boot();

        // Clear cache when tenant updated
        static::updated(function (Tenants $tenant) {
            $tenant->clearCache();
        });
    }

}
