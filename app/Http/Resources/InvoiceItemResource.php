<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->item_id,
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'line_total' => (float) $this->line_total,
            'period_start' => $this->period_start->format('Y-m-d'),
            'period_end' => $this->period_end->format('Y-m-d'),
            'period_label' => $this->period_label,
        ];
    }
}