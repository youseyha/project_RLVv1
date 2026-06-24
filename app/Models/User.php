<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use HasApiTokens; 
    use HasRoles;
    protected string $guard_name = 'web';
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    use HasUuids;    
    protected $table = 'users';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'username',
        'email',
        'password',  // Laravel expects 'password' in $fillable
        'role',
        'is_active',
        'last_login'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }
    /**
     *  Relationships
     */
    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenants::class, 'tenant_id', 'tenant_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branches::class, 'branch_id', 'branch_id');
    }

    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id', 'user_id');
    }
    public function movements()
    {
        return $this->hasMany(StockMovement::class,'user_id','user_id');
    }

    /**
     * OVERRIDE: Use tenant_id for multi-tenancy
     */
    public function getTeamIdAttribute()
    {
        return $this->tenant_id;
    }

    /**
     * HELPER METHODS
     */
    
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isManager(): bool
    {
        return $this->hasRole('manager');
    }

    public function isCashier(): bool
    { 
        return $this->hasRole('cashier');
    }
    
    public function isStaff(): bool
    {
        return $this->hasRole('staff');
    }

    /**
     * Assign role with tenant scope
     */
    public function assignRoleWithTenant(string $roleName): void
    {
        $this->assignRole($roleName);
        
        // Update pivot table with tenant_id
        DB::table('model_has_roles')
            ->where('model_id', $this->user_id)
            ->where('model_type', self::class)
            ->update(['tenant_id' => $this->tenant_id]);
    }

    /**
     * Check permission with tenant context
     */
    public function canInTenant(string $permission): bool
    {
        // Super admin can do everything
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->hasPermissionTo($permission);
    }
}
