<?php

namespace App\Jobs;

use App\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessExpiredInvoices implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $invoices = Invoice::where('status', 'pending')
            ->whereDate('due_date', '<', today())
            ->get();

        foreach ($invoices as $invoice) {

            $invoice->update([
                'status' => 'overdue'
            ]);

            Log::warning('Invoice marked as overdue', [
                'invoice_id' => $invoice->invoice_id,
                'invoice_number' => $invoice->invoice_number,
            ]);
        }
    }
}