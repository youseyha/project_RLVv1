<?php

namespace App\Events;

use App\Models\Inventory;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockDetected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $inventory;
    public $product;
    public $branch;

    public function __construct(Inventory $inventory)
    {
        $this->inventory = $inventory;
        $this->product = $inventory->product;
        $this->branch = $inventory->branch;
    }
}