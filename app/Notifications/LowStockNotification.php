<?php

namespace App\Notifications;

use App\Models\Inventory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class LowStockNotification extends Notification
{
    use Queueable;

    protected $inventory;

    public function __construct(Inventory $inventory)
    {
        $this->inventory = $inventory;
    }

    public function via($notifiable): array
    {
        return ['database']; // Can add 'mail' if needed
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'low_stock',
            'inventory_id' => $this->inventory->inventory_id,
            'product_id' => $this->inventory->product_id,
            'product_name' => $this->inventory->product->product_name,
            'product_code' => $this->inventory->product->product_code,
            'branch_id' => $this->inventory->branch_id,
            'branch_name' => $this->inventory->branch->branch_name,
            'quantity_on_hand' => $this->inventory->quantity_on_hand,
            'reorder_level' => $this->inventory->reorder_level,
            'reorder_quantity' => $this->inventory->reorder_quantity,
            'message' => sprintf(
                'Low stock alert: %s at %s. Current: %s, Reorder at: %s',
                $this->inventory->product->product_name,
                $this->inventory->branch->branch_name,
                $this->inventory->quantity_on_hand,
                $this->inventory->reorder_level
            ),
        ];
    }
}