<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Mail\InvoiceReminderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class SendInvoiceRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 2;

    /**
     * Execute the job
     */
    public function handle(): void
    {
        Log::info('Starting invoice reminders job');

        try {
            // ════════════════════════════════════════════════════════
            // Send reminders for invoices due in 3 days
            // ════════════════════════════════════════════════════════
            $this->sendUpcomingDueReminders();

            // ════════════════════════════════════════════════════════
            // Send reminders for invoices due today
            // ════════════════════════════════════════════════════════
            $this->sendDueTodayReminders();

            Log::info('Invoice reminders job completed successfully');

        } catch (\Exception $e) {
            Log::error('Failed to send invoice reminders', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send reminders for invoices due in 3 days
     */
    protected function sendUpcomingDueReminders(): void
    {
        $targetDate = now()->addDays(3)->toDateString();

        $invoices = Invoice::where('status', 'sent')
            ->whereDate('due_date', $targetDate)
            ->with(['tenant', 'subscription.plan'])
            ->get();

        foreach ($invoices as $invoice) {
            try {
                Mail::to($invoice->tenant->email)
                    ->send(new InvoiceReminderMail($invoice));

                Log::info('Reminder sent for upcoming due invoice', [
                    'invoice_number' => $invoice->invoice_number,
                    'tenant' => $invoice->tenant->company_name,
                    'due_date' => $invoice->due_date->format('Y-m-d'),
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to send reminder', [
                    'invoice_id' => $invoice->invoice_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send reminders for invoices due today
     */
    protected function sendDueTodayReminders(): void
    {
        $invoices = Invoice::where('status', 'sent')
            ->whereDate('due_date', today())
            ->with(['tenant', 'subscription.plan'])
            ->get();

        foreach ($invoices as $invoice) {
            try {
                Mail::to($invoice->tenant->email)
                    ->send(new InvoiceReminderMail($invoice));

                Log::info('Reminder sent for invoice due today', [
                    'invoice_number' => $invoice->invoice_number,
                    'tenant' => $invoice->tenant->company_name,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to send reminder', [
                    'invoice_id' => $invoice->invoice_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}