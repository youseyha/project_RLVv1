<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Branches extends Model
{
    use HasUuids;

    protected $primaryKey = 'branch_id';//ប្តូរឈ្មោះ PK
    public $incrementing = false;//PK មិន auto increment
    protected $keyType = 'string';//PK ជា string (UUID)

    protected $fillable = [
        'tenant_id',
        'branch_name',
        'branch_code',
        'address',
        'phone',
        'manager_name',
        'is_active'
    ];
    // Branch belongs to Tenant
    public function tenant()
    {
        return $this->belongsTo(Tenants::class, 'tenant_id', 'tenant_id');
    }
    public function users()
    {
        return $this->hasMany(User::class, 'branch_id', 'branch_id');
    }
    public function movements()
    {
        return $this->hasMany(StockMovement::class,'branch_id','branch_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

     /**
     * Get the manager of this branch
     */
    public function manager()
    {
        return $this->hasOne(User::class, 'branch_id', 'branch_id')
            ->whereHas('roles', function ($query) {
                $query->where('name', 'manager');
            });
    }

    /**
     * Get all staff in this branch (excluding manager)
     */
    public function staff()
    {
        return $this->hasMany(User::class, 'branch_id', 'branch_id')
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'manager');
            });
    }
}
