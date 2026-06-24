<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GatewayBranch extends Model
{
    use HasUuids;
    
    protected $primaryKey = 'branch_mapping_id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    public $timestamps = false;
    
    protected $fillable = [
        'gateway_id',
        'branch_identifier',
        'branch_name',
        'country',
        'is_active',
    ];
    
    protected $casts = [
        'is_active' => 'boolean',
    ];
    
    // Relationships
    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id', 'gateway_id');
    }
    
    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function scopeByCountry($query, $countryCode)
    {
        return $query->where('country', $countryCode);
    }
}