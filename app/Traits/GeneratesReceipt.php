<?php

namespace App\Traits;

use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;

trait GeneratesReceipt
{
    public function generateReceipt(Transaction $transaction)
    {
        $pdf = Pdf::loadView('receipts.template', [
            'transaction' => $transaction,
        ]);

        $filename = "receipt-{$transaction->transaction_number}.pdf";

        return $pdf->download($filename);

    }
}