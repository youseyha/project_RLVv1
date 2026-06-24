<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReorderSuggestionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $data;

    /**
     * Create a new notification instance.
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $branchName = $this->getBranchName();
        $totalItems = $this->data['total_items'];
        $criticalCount = $this->data['critical_count'];

        return (new MailMessage)
            ->subject("⚠️ Low Stock Alert - {$branchName}")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("You have low stock items that need attention.")
            ->line("**Branch:** {$branchName}")
            ->line("**Total Low Stock Items:** {$totalItems}")
            ->line("**Critical Items:** {$criticalCount}")
            ->action('View Inventory', url('/inventory/low-stock'))
            ->line('Please review and place orders as needed.')
            ->line('Thank you for using our POS System!');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'reorder_suggestion',
            'branch_id' => $this->data['branch_id'],
            'total_items' => $this->data['total_items'],
            'critical_count' => $this->data['critical_count'],
            'items' => $this->data['items'],
            'message' => "Low stock alert: {$this->data['total_items']} items need reordering",
        ];
    }

    /**
     * Get branch name from items
     */
    protected function getBranchName(): string
    {
        if (isset($this->data['items'][0]['branch_name'])) {
            return $this->data['items'][0]['branch_name'];
        }
        return 'Unknown Branch';
    }
}