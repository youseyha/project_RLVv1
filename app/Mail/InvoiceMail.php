<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice->load(['subscription.plan', 'items', 'tenant']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Invoice {$this->invoice->invoice_number} - {$this->invoice->tenant->company_name}",
            from: config('invoice.email.from_address', config('mail.from.address')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'tenant' => $this->invoice->tenant,
                'subscription' => $this->invoice->subscription,
                'plan' => $this->invoice->subscription->plan,
            ],
        );
    }

    public function attachments(): array
    {
        // Generate PDF and attach
        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $this->invoice,
        ]);

        return [
            Attachment::fromData(fn () => $pdf->output(), "invoice-{$this->invoice->invoice_number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}