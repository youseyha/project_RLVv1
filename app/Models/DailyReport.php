<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyReport extends Model
{
    use HasUuids;
    
    protected $table = 'daily_reports';
    protected $primaryKey = 'report_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    // const CREATED_AT = null;
    // const UPDATED_AT = null;
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'report_date',
        'total_sales',
        'transaction_count',
        'average_transaction',
        'total_tax',
        'total_discount',
        'customer_count',
        'generated_at',
    ];
    protected $casts = [
        'report_date' => 'date',
        'total_sales' => 'decimal:2',
        'transaction_count' => 'integer',
        'average_transaction' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'total_discount' => 'decimal:2',
        'customer_count' => 'integer',
        'generated_at' => 'datetime',
    ];
    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenants::class, 'tenant_id', 'tenant_id');
    }
    
    public function branch()
    {
        return $this->belongsTo(Branches::class, 'branch_id', 'branch_id');
    }

    // Scopes
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('report_date', $date);
    }

    public function scopeForMonth($query, $year, $month)
    {
        return $query->whereYear('report_date', $year)
                     ->whereMonth('report_date', $month);
    }

    public function scopeAllBranches($query)
    {
        return $query->whereNull('branch_id');
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('report_date', '>=', now()->subDays($days));
    }
    
    // Accessors
    public function getIsAllBranchesAttribute(): bool
    {
        return is_null($this->branch_id);
    }

    public function getGrossProfitAttribute(): float
    {
        $branchId = $this->branch_id;
        $reportDate = $this->report_date instanceof \Carbon\Carbon
                    ? $this->report_date->toDateString()
                    : $this->report_date;

        // COST FROM COMPLETED SALES
        $salesCost = TransactionItem::join('products','transaction_items.product_id','=','products.product_id')
                    ->whereHas('transaction', function ($q) use ($branchId, $reportDate) {
                        $q->whereDate('transaction_date', $reportDate)
                        ->where('status', 'completed');
                        if ($branchId) {
                            $q->where('branch_id', $branchId);
                        }
                    })
                    ->sum(DB::raw('transaction_items.quantity * products.cost_price'));

        // COST FROM REFUNDS   
        $refundCost = TransactionItem::join('products','transaction_items.product_id','=','products.product_id')
                    ->whereHas('transaction', function ($q) use ($branchId, $reportDate) {
                        $q->whereDate('transaction_date', $reportDate)
                        ->where('status', 'refunded');
                        if ($branchId) {
                            $q->where('branch_id', $branchId);
                        }
                    })
                    ->sum(DB::raw('transaction_items.quantity * products.cost_price'));

        // NET COST
        $netCost = $salesCost - $refundCost;

        return round($this->total_sales - $netCost, 2);
    }

    public function getNetSalesAttribute(): float
    {
        return round($this->total_sales - $this->total_discount, 2);
    }
    
    // Methods
    public static function reportExists($tenantId, $branchId, $date): bool
    {
        return self::where('tenant_id', $tenantId)
                   ->where('branch_id', $branchId)
                   ->where('report_date', $date)
                   ->exists();
    }
}
