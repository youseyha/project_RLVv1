<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceOverdueMail extends Mailable
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
            subject: "⚠️ OVERDUE Invoice {$this->invoice->invoice_number}",
            from: config('invoice.email.from_address', config('mail.from.address')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-overdue',
            with: [
                'invoice' => $this->invoice,
                'tenant' => $this->invoice->tenant,
                'daysOverdue' => $this->invoice->days_overdue,
                'amountDue' => $this->invoice->amount_due,
            ],
        );
    }
}