<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasUuids;
    
    protected $table = 'payments';
    protected $primaryKey = 'payment_id';
    public $incrementing = false;
    protected $keyType = 'string';
    const UPDATED_AT = null;
    protected $fillable = [
        'method_id',
        'gateway_id',
        'invoice_id',
        'transaction_id',
        'payment_reference',
        'amount',
        'payment_date',
        'payment_type',
        'status',
        'gateway_transaction_id',
        'gateway_response',
        'method_snapshot',
        'gateway_snapshot',
    ];
    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'gateway_response' => 'array',
        'method_snapshot' => 'array',
        'gateway_snapshot' => 'array',
        'created_at' => 'datetime',
    ];
    
    // Relationships
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }
    
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }
    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'method_id', 'method_id');
    }

    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id', 'gateway_id');
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }
    
    // Accessors
    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    public function getIsRefundAttribute(): bool
    {
        return $this->status === 'refunded' || $this->amount < 0;
    }

    public function getIsPendingAttribute(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }
    
    public function getIsFailedAttribute(): bool
    {
        return $this->status === 'failed';
    }

    public function getAbsoluteAmountAttribute(): float
    {
        return abs($this->amount);
    }
}
