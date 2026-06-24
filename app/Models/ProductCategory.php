<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ProductCategory extends Model
{
    use HasUuids;

    protected $primaryKey = 'category_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'category_name',
        'description',
        'parent_category_id', // From ERD (for hierarchical categories)
        'image_url',          
        'is_active',       
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenants::class, 'tenant_id', 'tenant_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id', 'category_id');
    }

    // Hierarchical relationships
    public function parent()
    {
        return $this->belongsTo(ProductCategory::class, 'parent_category_id', 'category_id');
    }

    public function children()
    {
        return $this->hasMany(ProductCategory::class, 'parent_category_id', 'category_id');
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

    public function scopeRootCategories($query)
    {
        return $query->whereNull('parent_category_id');
    }
}