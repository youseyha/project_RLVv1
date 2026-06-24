<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Mail\InvoiceOverdueMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DetectOverdueInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 2;

    public function handle(InvoiceService $invoiceService): void
    {
        Log::info('Starting overdue invoices detection');

        try {
            // ជ្រើសរើស invoices ដែល status = sent និង due_date < now
            $count = $invoiceService->detectOverdueInvoices();

            Log::info("Marked {$count} invoices as overdue");

            // ផ្ញើ reminder emails
            $this->sendOverdueReminders();

        } catch (\Exception $e) {
            Log::error('Failed to detect overdue invoices', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function sendOverdueReminders(): void
    {
        $overdueInvoices = Invoice::where('status', 'overdue')
            ->with(['tenant', 'subscription'])
            ->get();

        foreach ($overdueInvoices as $invoice) {
            try {
                Mail::to($invoice->tenant->email)
                    ->send(new InvoiceOverdueMail($invoice));

                Log::info('Overdue reminder sent', [
                    'invoice_number' => $invoice->invoice_number,
                    'tenant' => $invoice->tenant->company_name,
                    'days_overdue' => $invoice->days_overdue,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to send overdue reminder', [
                    'invoice_id' => $invoice->invoice_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
     /**
    * Send reminder emails for overdue invoices
     */
    protected function sendReminderEmails(): void
    {
        $overdueInvoices = Invoice::overdue()
            ->with('tenant')
            ->where(function ($query) {
                // Send reminder if:
                // - Never sent before, OR
                // - Last reminder was 7+ days ago
                $query->whereNull('last_reminder_sent_at')
                      ->orWhere('last_reminder_sent_at', '<=', now()->subDays(7));
            })
            ->get();

        foreach ($overdueInvoices as $invoice) {
            try {
                // Send email
                Mail::to($invoice->tenant->email)
                    ->send(new InvoiceOverdueMail($invoice));

                // Update last reminder timestamp
                $invoice->update([
                    'last_reminder_sent_at' => now(),
                ]);

                Log::info('Overdue reminder sent', [
                    'invoice_number' => $invoice->invoice_number,
                    'tenant' => $invoice->tenant->company_name,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to send overdue reminder', [
                    'invoice_id' => $invoice->invoice_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}