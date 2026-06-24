<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasUuids;

    protected $primaryKey = 'movement_id';
    public $incrementing = false;
    protected $keyType = 'string';

    const TYPE_PURCHASE = 'purchase';       // ទិញចូល
    const TYPE_SALE = 'sale';               // លក់ចេញ
    const TYPE_ADJUSTMENT_IN = 'adjustment_in';   // កែតម្រូវចូល
    const TYPE_ADJUSTMENT_OUT = 'adjustment_out';  // កែតម្រូវចេញ
    const TYPE_TRANSFER_IN = 'transfer_in'; // ទទួលពីសាខាផ្សេង
    const TYPE_TRANSFER_OUT = 'transfer_out'; // ផ្ទេរទៅសាខាផ្សេង
    const TYPE_DAMAGE = 'damage';           // ខូច
    const TYPE_RETURN_TO_SUPPLIER = 'return_to_supplier';  // ត្រឡប់មកវិញអ្នកផ្គត់ផ្គង់
    const TYPE_RETURN_FROM_CUSTOMER = 'return_from_customer'; // ត្រឡប់មកវិញពីអតិថិជន

    protected $fillable = [
        'inventory_id',
        'product_id',
        'branch_id',
        'user_id',
        'movement_type',
        'quantity',
        'quantity_before',
        'quantity_after',
        'reference_number',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'quantity_before' => 'decimal:2',
        'quantity_after' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    // Relationships
    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'inventory_id', 'inventory_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branches::class, 'branch_id', 'branch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // Scopes
    public function scopeTransfers($query)
    {
        return $query->whereIn('movement_type', [
            self::TYPE_TRANSFER_IN,
            self::TYPE_TRANSFER_OUT
        ]);
    }
    public function scopeByType($query, string $type)
    {
        return $query->where('movement_type', $type);
    }
    public function scopeByReference($query, string $referenceNumber)
    {
        return $query->where('reference_number', $referenceNumber);
    }
    public function scopeByProduct($query, string $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
    
    // ACCESSORS - Calculated Fields
    
    public function getIsTransferAttribute(): bool
    {
        return in_array($this->movement_type, [
            self::TYPE_TRANSFER_IN,
            self::TYPE_TRANSFER_OUT
        ]);
    }

    public function getIsDeductionAttribute(): bool
    {
        return $this->quantity < 0;
    }

    public function getIsAdditionAttribute(): bool
    {
        return $this->quantity > 0;
    }

    public function getDirectionAttribute(): ?string
    {
        if ($this->movement_type === self::TYPE_TRANSFER_OUT) {
            return 'outgoing';
        } elseif ($this->movement_type === self::TYPE_TRANSFER_IN) {
            return 'incoming';
        }
        return null;
    }

}
