<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasUuids;
    
    protected $table = 'invoices';
    protected $primaryKey = 'invoice_id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    // គ្មាន updated_at
    const UPDATED_AT = null;
    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'amount_paid',
        'amount_due',
        'status',
    ];
    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'created_at' => 'datetime',
    ];
    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenants::class, 'tenant_id', 'tenant_id');
    }
    
    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'subscription_id');
    }
    
    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id', 'invoice_id');
    }
    
    /**
     * មូលហេតុ:
     * - អាចទូទាត់ច្រើនដង (partial payments)
     * - អាចសងវិញច្រើនដង (multiple refunds)
     * - អាចប្រើវិធីទូទាត់ផ្សេងៗគ្នា
     */
    public function payments()
    {
        return $this->hasMany(Payment::class, 'invoice_id', 'invoice_id');
    }
     /**
     * ════════════════════════════════════════════════════════════
     * PAYMENT RELATIONSHIP HELPERS
     * ════════════════════════════════════════════════════════════
     */
    
    // ការទូទាត់ទាំងអស់
    public function allPayments()
    {
        return $this->payments();
    }

    // ការទូទាត់ដែលជោគជ័យ
    public function successfulPayments()
    {
        return $this->payments()->where('status', 'completed');
    }

    // ការសងប្រាក់វិញ
    public function refunds()
    {
        return $this->payments()
            ->where('status', 'refunded')
            ->orWhere('amount', '<', 0);
    }

    // ការទូទាត់កំពុងដំណើរការ
    public function pendingPayments()
    {
        return $this->payments()->whereIn('status', ['pending', 'processing']);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * SCOPES
     * ════════════════════════════════════════════════════════════
     */
    
    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue')
                     ->orWhere(function ($q) {
                         $q->where('status', 'sent')
                           ->where('due_date', '<', now());
                     });
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * ════════════════════════════════════════════════════════════
     * ACCESSORS
     * ════════════════════════════════════════════════════════════
     */
    
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'overdue' || 
               ($this->status === 'sent' && $this->due_date->isPast());
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->status === 'paid';
    }

    public function getIsDraftAttribute(): bool
    {
        return $this->status === 'draft';
    }

    public function getDaysOverdueAttribute(): int
    {
        if (!$this->is_overdue) {
            return 0;
        }
        
        return now()->diffInDays($this->due_date, false);
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return $this->amount_due <= 0;
    }

    public function getBalanceAttribute(): float
    {
        return $this->amount_due;
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PAYMENT CALCULATION HELPERS
     * ════════════════════════════════════════════════════════════
     */
    
    /**
     * គណនាប្រាក់ដែលបានទទួលពីការទូទាត់ទាំងអស់
     */
    public function getTotalPaidFromPaymentsAttribute(): float
    {
        return $this->successfulPayments()
            ->where('amount', '>', 0)
            ->sum('amount');
    }

    /**
     * គណនាប្រាក់ដែលបានសងវិញ
     */
    public function getTotalRefundedAttribute(): float
    {
        return abs($this->payments()
            ->where('status', 'refunded')
            ->sum('amount'));
    }

    /**
     * ចំនួនការទូទាត់
     */
    public function getPaymentCountAttribute(): int
    {
        return $this->payments()->count();
    }

    /**
     * ចំនួនការសងប្រាក់វិញ
     */
    public function getRefundCountAttribute(): int
    {
        return $this->refunds()->count();
    }

    /**
     * តាមដាន payment methods ដែលបានប្រើ
     */
    public function getPaymentMethodsUsedAttribute(): array
    {
        return $this->successfulPayments()
            ->with('method')
            ->get()
            ->pluck('method.method_name')
            ->unique()
            ->values()
            ->toArray();
    }
}
