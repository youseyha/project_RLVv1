<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    use HasUuids;
    
    protected $table = 'plan_features';
    protected $primaryKey = 'feature_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'plan_id',
        'feature_name',
        'feature_code',
        'is_enabled',
        'description',
    ];
    protected $casts = [
        'is_enabled' => 'boolean',
    ];
    // Relationships
    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id', 'plan_id');
    }
    
    // Scopes
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
    
    public function scopeByCode($query, $code)
    {
        return $query->where('feature_code', $code);
    }
}
