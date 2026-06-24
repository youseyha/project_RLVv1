<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionRenewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Subscription $subscription;
    protected Invoice $invoice;

    public function __construct(Subscription $subscription, Invoice $invoice)
    {
        $this->subscription = $subscription;
        $this->invoice = $invoice;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("🎉 Subscription Renewed Successfully")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("Your subscription has been renewed successfully!")
            ->line("**Plan:** {$this->subscription->plan->plan_name}")
            ->line("**New End Date:** {$this->subscription->end_date->format('d M Y')}")
            ->line("**Invoice Number:** {$this->invoice->invoice_number}")
            ->line("**Amount:** \$" . number_format($this->invoice->total_amount, 2))
            ->action('View Invoice', url('/invoices/' . $this->invoice->invoice_id))
            ->line('Thank you for your continued subscription!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_renewed',
            'subscription_id' => $this->subscription->subscription_id,
            'plan_name' => $this->subscription->plan->plan_name,
            'end_date' => $this->subscription->end_date->format('Y-m-d'),
            'invoice_id' => $this->invoice->invoice_id,
            'amount' => $this->invoice->total_amount,
        ];
    }
}