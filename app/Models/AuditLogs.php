<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLogs extends Model
{
    use HasUuids;
    protected $table = 'audit_logs';
    protected $primaryKey = 'log_id';
    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = null;  // មិនមាន updated_at
    protected $fillable = [
        'user_id',
        'tenant_id',
        'action_type',
        'table_name',
        'record_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];
    protected $casts = [
        'old_values' => 'array',  // JSON → Array
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];
    
    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
    
    public function tenant()
    {
        return $this->belongsTo(Tenants::class, 'tenant_id', 'tenant_id');
    }
    
}
