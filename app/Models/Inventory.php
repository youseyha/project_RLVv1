<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    //Create UUID (Universally Unique Identifier) ស្វ័យប្រវត្តិសម្រាប់ Primary Key
    use HasUuids;

    protected $table = 'inventories';
    protected $primaryKey = 'inventory_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'branch_id',
        'product_id',
        'quantity_on_hand',
        'quantity_reserved',
        'reorder_level',
        'reorder_quantity',
        'last_updated'
    ];
    protected $casts = [
        'quantity_on_hand' => 'decimal:2',
        'quantity_reserved' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'reorder_quantity' => 'decimal:2',
        'last_updated' => 'datetime',
    ];
    protected $appends = ['quantity_available', 'stock_status', 'is_low_stock','is_out_of_stock'];
    // Relationships
    public function branch()
    {
        return $this->belongsTo(Branches::class, 'branch_id', 'branch_id');
    }
    
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
    public function movements()
    {
        return $this->hasMany(StockMovement::class, 'inventory_id', 'inventory_id');
    }
    
    // Scopes
    public function scopeLowStock($query)
    {
        return $query->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_level')
                ->whereRaw('(quantity_on_hand - quantity_reserved) > 0');
    }

    public function scopeOutOfStock($query)
    {
        return $query->whereRaw('(quantity_on_hand - quantity_reserved) <= 0');
    }

    public function scopeByBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    // Accessors
    public function getQuantityAvailableAttribute(): float
    {
        return $this->quantity_on_hand - $this->quantity_reserved;
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->quantity_available  <= $this->reorder_level 
               && $this->quantity_available  > 0;
    }

    public function getIsOutOfStockAttribute(): bool
    {
        return $this->quantity_available  <= 0;
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->quantity_available  <= 0) {
            return 'out_of_stock';
        } elseif ($this->quantity_available  <= $this->reorder_level) {
            return 'low_stock';
        } else {
            return 'in_stock';
        }
    }

    // Methods
    public function updateAvailableQuantity(): void
    {
        $this->quantity_available = $this->quantity_on_hand - $this->quantity_reserved;
        $this->last_updated = now();
        $this->save();
    }
}
