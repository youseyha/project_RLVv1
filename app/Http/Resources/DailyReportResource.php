<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyReportResource extends JsonResource
{
    /**
     * DAILY REPORT RESOURCE 
     */
    public function toArray(Request $request): array
    {
        return [
            'report_id' => $this->report_id,
            
            // Branch Info
            'branch' => $this->when($this->branch, [
                'branch_id' => $this->branch?->branch_id,
                'branch_name' => $this->branch?->branch_name,
                'branch_code' => $this->branch?->branch_code,
            ]),
            'is_all_branches' => $this->is_all_branches,
            
            // Date
            'report_date' => $this->report_date->format('Y-m-d'),
            'report_date_formatted' => $this->report_date->format('d M Y'),
            
            // Metrics តាម ERD
            'total_sales' => (float) $this->total_sales,
            'transaction_count' => (int) $this->transaction_count,
            'average_transaction' => (float) $this->average_transaction,
            'total_tax' => (float) $this->total_tax,
            'total_discount' => (float) $this->total_discount,
            'customer_count' => (int) $this->customer_count,
            
            // Calculated Fields
            'net_sales' => (float) $this->net_sales,
            'gross_profit' => (float) $this->gross_profit,
            
            // Timestamps
            'generated_at' => $this->generated_at->format('Y-m-d H:i:s'),
        ];
    }
}