<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PosTerminal extends Model
{
    use HasUuids;

    protected $table = 'pos_terminals';
    protected $primaryKey = 'terminal_id';
    public $incrementing = false;
    protected $keyType = 'string';
    // បើមិនមាន updated_at
    const UPDATED_AT = null;
    protected $fillable = [
        'branch_id',
        'terminal_code',
        'device_id',
        'ip_address',
        'status',
        'last_sync',
    ];
    protected $casts = [
        'last_sync' => 'datetime',
        'created_at' => 'datetime',
    ];
    // Relationships
    public function branch()
    {
        return $this->belongsTo(Branches::class, 'branch_id', 'branch_id');
    }
    
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'terminal_id', 'terminal_id');
    }
}
