<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\Crypt;

class PaymentGateway extends Model
{
    use HasUuids;
    
    protected $table = 'payment_gateways';
    protected $primaryKey = 'gateway_id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    const UPDATED_AT = null;
    
    protected $fillable = [
        'gateway_name',
        'gateway_code',
        'api_endpoint',
        'api_credentials_encrypted',
        'transaction_fee_percentage',
        'transaction_fee_fixed',
        'status',
    ];
    
    protected $casts = [
        'transaction_fee_percentage' => 'decimal:2',
        'transaction_fee_fixed' => 'decimal:2',
        'created_at' => 'datetime',
    ];
    
    // Relationships
    public function branches()
    {
        return $this->hasMany(GatewayBranch::class, 'gateway_id', 'gateway_id');
    }
    
    public function activeBranches()
    {
        return $this->hasMany(GatewayBranch::class, 'gateway_id', 'gateway_id')
                    ->where('is_active', true);
    }
    
    public function payments()
    {
        return $this->hasMany(Payment::class, 'gateway_id', 'gateway_id');
    }
    
    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
    public function scopeByCode($query, string $code)
    {
        return $query->where('gateway_code', $code);
    }
    
    // Accessors
    public function getCredentialsAttribute(): array
    {
        if (empty($this->api_credentials_encrypted)) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($this->api_credentials_encrypted), true);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function setCredentialsAttribute(array $credentials): void
    {
        $this->attributes['api_credentials_encrypted'] = Crypt::encryptString(
            json_encode($credentials)
        );
    }
    
    public function getBranchForCountry($countryCode)
    {
        return $this->branches()
                    ->where('country', $countryCode)
                    ->where('is_active', true)
                    ->first();
    }
     /**
     * ════════════════════════════════════════════════════════════
     * HELPER METHODS
     * ════════════════════════════════════════════════════════════
     */
    
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function calculateFee(float $amount): float
    {
        $percentageFee = ($amount * $this->transaction_fee_percentage) / 100;
        return round($percentageFee + $this->transaction_fee_fixed, 2);
    }
}