<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected array $summary;
    protected array $comparison;

    public function __construct(array $summary, array $comparison)
    {
        $this->summary = $summary;
        $this->comparison = $comparison;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $trend = $this->comparison['trend'] === 'up' ? '📈' : 
                ($this->comparison['trend'] === 'down' ? '📉' : '➡️');

        return (new MailMessage)
            ->subject("📊 Weekly Sales Report")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("Here is your weekly sales summary:")
            ->line("**Week:** {$this->summary['week_start']} to {$this->summary['week_end']}")
            ->line("**Total Sales:** \$" . number_format($this->summary['total_sales'], 2))
            ->line("**Transactions:** " . number_format($this->summary['total_transactions']))
            ->line("**Average Daily Sales:** \$" . number_format($this->summary['average_daily_sales'], 2))
            ->line("**Week-over-Week:** {$trend} " . 
                   ($this->comparison['change_percentage'] > 0 ? '+' : '') . 
                   $this->comparison['change_percentage'] . "%")
            ->action('View Full Report', url('/reports/weekly'))
            ->line('Thank you!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'weekly_report',
            'summary' => $this->summary,
            'comparison' => $this->comparison,
        ];
    }
}