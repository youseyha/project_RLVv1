<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionRenewalFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Subscription $subscription;
    protected string $reason;

    public function __construct(Subscription $subscription, string $reason)
    {
        $this->subscription = $subscription;
        $this->reason = $reason;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("⚠️ Subscription Renewal Failed")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("We were unable to renew your subscription.")
            ->line("**Plan:** {$this->subscription->plan->plan_name}")
            ->line("**Expiry Date:** {$this->subscription->end_date->format('d M Y')}")
            ->line("**Reason:** {$this->reason}")
            ->action('Update Payment Method', url('/billing/payment-methods'))
            ->line('Please update your payment information to continue your subscription.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_renewal_failed',
            'subscription_id' => $this->subscription->subscription_id,
            'plan_name' => $this->subscription->plan->plan_name,
            'end_date' => $this->subscription->end_date->format('Y-m-d'),
            'reason' => $this->reason,
        ];
    }
}