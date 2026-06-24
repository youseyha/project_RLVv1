<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice->load(['subscription.plan', 'tenant']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🔔 Payment Reminder - Invoice {$this->invoice->invoice_number}",
            from: config('invoice.email.from_address', config('mail.from.address')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-reminder',
            with: [
                'invoice' => $this->invoice,
                'tenant' => $this->invoice->tenant,
                'daysUntilDue' => now()->diffInDays($this->invoice->due_date, false),
            ],
        );
    }
}