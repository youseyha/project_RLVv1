<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlyInventoryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected array $inventorySummary;
    protected array $movementSummary;
    protected array $reorderRecommendations;
    protected $month;

    public function __construct($inventorySummary, $movementSummary, $reorderRecommendations, $month)
    {
        $this->inventorySummary = $inventorySummary;
        $this->movementSummary = $movementSummary;
        $this->reorderRecommendations = $reorderRecommendations;
        $this->month = $month;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("📦 Monthly Inventory Report - " . $this->month->format('F Y'))
            ->greeting("Hello {$notifiable->full_name},")
            ->line("Here is your monthly inventory summary:")
            ->line("**Total Products:** " . number_format($this->inventorySummary['total_products']))
            ->line("**Inventory Value:** \$" . number_format($this->inventorySummary['inventory_value'], 2))
            ->line("**Stock Health:** " . $this->inventorySummary['stock_health_percentage'] . "%");

        if ($this->reorderRecommendations['total_items'] > 0) {
            $message->line("⚠️ **Reorder Needed:** " . 
                          $this->reorderRecommendations['total_items'] . " items");
        }

        return $message
            ->action('View Full Report', url('/reports/inventory'))
            ->line('Thank you!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'monthly_inventory',
            'inventory_summary' => $this->inventorySummary,
            'movement_summary' => $this->movementSummary,
            'reorder_recommendations' => $this->reorderRecommendations,
        ];
    }
}