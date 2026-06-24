<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasUuids;

    protected $table = 'payment_methods';
    protected $primaryKey = 'method_id';
    public $incrementing = false;
    protected $keyType = 'string';
    const UPDATED_AT = null;
    
    protected $fillable = [
        'tenant_id',
        'method_name',
        'method_type',
        'is_default',
        'is_active',
    ];
    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];
    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenants::class, 'tenant_id', 'tenant_id');
    }
    
    public function payments()
    {
        return $this->hasMany(Payment::class, 'method_id', 'method_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
    
    public function scopeByType($query, $type)
    {
        return $query->where('method_type', $type);
    }
}
