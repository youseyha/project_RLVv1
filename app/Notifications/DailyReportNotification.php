<?php

namespace App\Notifications;

use App\Models\DailyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected DailyReport $report;

    /**
     * ════════════════════════════════════════════════════════════
     * DAILY REPORT NOTIFICATION
     * ════════════════════════════════════════════════════════════
     * 
     * ពន្យល់: ផ្ញើរបាយការណ៍តាមអ៊ីមែល និង Database
     */
    public function __construct(DailyReport $report)
    {
        $this->report = $report;
    }

    /**
     * Get notification delivery channels
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get mail representation
     */
    public function toMail(object $notifiable): MailMessage
    {
        $report = $this->report;
        $date = $report->report_date->format('d M Y');
        $branchName = $report->branch?->branch_name ?? 'All Branches';

        return (new MailMessage)
            ->subject("📊 Daily Sales Report - {$date}")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("Here is your daily sales report for **{$branchName}**.")
            ->line("**Date:** {$date}")
            ->line("**Total Sales:** \$" . number_format($report->total_sales, 2))
            ->line("**Transactions:** " . number_format($report->transaction_count))
            ->line("**Average Transaction:** \$" . number_format($report->average_transaction, 2))
            ->line("**Total Tax:** \$" . number_format($report->total_tax, 2))
            ->line("**Total Discount:** \$" . number_format($report->total_discount, 2))
            ->line("**Customers:** " . number_format($report->customer_count))
            ->action('View Full Report', url('/reports/daily/' . $report->report_id))
            ->line('Thank you for using our POS System!');
    }

    /**
     * Get database representation
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'daily_report',
            'report_id' => $this->report->report_id,
            'report_date' => $this->report->report_date->format('Y-m-d'),
            'total_sales' => $this->report->total_sales,
            'transaction_count' => $this->report->transaction_count,
            'branch_name' => $this->report->branch?->branch_name ?? 'All Branches',
            'message' => "Daily sales report for {$this->report->report_date->format('d M Y')} is ready.",
        ];
    }
}