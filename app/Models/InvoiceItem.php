<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasUuids;
    protected $table = 'invoice_items';
    protected $primaryKey = 'item_id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    // គ្មាន timestamps
    public $timestamps = false;
    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'period_start',
        'period_end',
    ];
    
    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
    ];
    // Relationships
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }
    
    // Accessors
    public function getPeriodLabelAttribute(): string
    {
        return $this->period_start->format('Y-m-d') . ' - ' . 
               $this->period_end->format('Y-m-d');
    }
    
    public function getFormattedLineTotalAttribute()
    {
        return '$' . number_format($this->line_total, 2);
    }
    
    public function getPeriodDescriptionAttribute()
    {
        if ($this->period_start && $this->period_end) {
            return $this->period_start->format('M j') . ' - ' . $this->period_end->format('M j, Y');
        }
        return null;
    }
    
    // Methods
    public function calculateLineTotal()
    {
        $this->line_total = $this->quantity * $this->unit_price;
        return $this;
    }
    
    // Events
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($item) {
            // Auto-calculate line_total
            if (empty($item->line_total)) {
                $item->line_total = $item->quantity * $item->unit_price;
            }
        });
    }
}
