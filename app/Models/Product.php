<?php
// app/Models/Product.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Product extends Model
{
    use HasUuids;

    protected $primaryKey = 'product_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'category_id',
        'product_code',
        'product_name',
        'description',
        'base_price',
        'cost_price',
        'image_url', 
        'stock_quantity',
        'is_active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['profit_margin', 'is_low_stock'];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenants::class, 'tenant_id', 'tenant_id');
    }
    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id', 'category_id');
    }
    public function movements()
    {
        return $this->hasMany(StockMovement::class,'product_id','product_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    public function scopeLowStock($query)
    {
        return $query->where('stock_quantity', '>', 0)
                     ->where('stock_quantity', '<=', 10);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('product_name', 'like', "%{$search}%")
              ->orWhere('product_code', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%");
        });
    }

    // Accessors
    public function getProfitMarginAttribute(): float
    {
        if ($this->cost_price == 0) return 0;
        return round((($this->base_price - $this->cost_price) / $this->cost_price) * 100, 2);
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->stock_quantity > 0 && $this->stock_quantity <= 10;
    }
}