<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdfService
{
    /**
     * Generate PDF for invoice
     */
    public function generate(Invoice $invoice)
    {
        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice->load(['items', 'tenant']),
        ]);

        return $pdf;
    }

    /**
     * Download PDF
     */
    public function download(Invoice $invoice)
    {
        return $this->generate($invoice)
            ->download("invoice-{$invoice->invoice_number}.pdf");
    }

    /**
     * Stream PDF (view in browser)
     */
    public function stream(Invoice $invoice)
    {
        return $this->generate($invoice)
            ->stream("invoice-{$invoice->invoice_number}.pdf");
    }

    /**
     * Save PDF to storage
     */
    public function save(Invoice $invoice, string $path = 'invoices')
    {
        $filename = "invoice-{$invoice->invoice_number}.pdf";
        $fullPath = "{$path}/{$filename}";

        $this->generate($invoice)->save(storage_path("app/{$fullPath}"));

        return $fullPath;
    }
}