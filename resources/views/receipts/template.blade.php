<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt - {{ $transaction->transaction_number }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Play:wght@400;700&display=swap');
        body {  font-family: "Play", sans-serif;
        font-weight: 400;
        font-style: normal; margin: 0; padding: 20px; }
        .header { margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header h2 { margin-bottom: 30px; font-size: 20px;color : #1d3557;text-transform: uppercase; text-align: center; }
        .header p {width : 100%; margin: 2px 0; }
        .info { margin-bottom: 15px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th { background-color: #f0f0f0; padding: 8px; text-align: left; border-bottom: 2px solid #000; }
        td { padding: 6px; border-bottom: 1px solid #ddd; }
        .totals { margin-top: 20px; border-top: 2px solid #000; padding-top: 10px; }
        .total-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .total-row.grand-total { font-size: 16px; font-weight: bold; margin-top: 10px; padding-top: 10px; border-top: 1px solid #000;color : #c1121f }
        .footer { margin-top: 30px; font-size: 10px; border-top: 1px dashed #000; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Receipt</h2>
        <p>Company name : <span style="text-transform: uppercase;font-weight: bold;">{{ $transaction->branch->tenant->company_name }}</span></p>
        <p>Branch : {{ $transaction->branch->branch_name }}</p>
        <p>Address : {{ $transaction->branch->address ?? '' }}</p>
        <p>Tel : {{ $transaction->branch->phone ?? '' }}</p>
    </div>
    <div class="info">
        <div class="info-row">
            <span>Receipt No:</span>
            <strong style="color: #1d3557;">{{ $transaction->transaction_number }}</strong>
        </div>
        <div class="info-row">
            <span>Date:</span>
            <span>{{ $transaction->transaction_date->format('d/m/Y H:i:s A') }}</span>
        </div>
        <div class="info-row">
            <span>Cashier:</span>
            <span>{{ $transaction->user->full_name ?? $transaction->user->username }}</span>
        </div>
        @if($transaction->terminal_id)
        <div class="info-row">
            <span>Terminal:</span>
            <span>{{ $transaction->terminal_id }}</span>
        </div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 45%">Item</th>
                <th style="width: 15%; text-align: right">Price</th>
                <th style="width: 15%; text-align: right">Qty</th>
                <th style="width: 10%; text-align: right">Disc</th>
                <th style="width: 15%; text-align: right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transaction->items as $item)
            <tr>
                <td>{{ $item->product_name }}</td>
                <td style="text-align: right">${{ number_format($item->unit_price, 2) }}</td>
                <td style="text-align: right">{{ number_format($item->quantity, 2) }}</td>
                <td style="text-align: right">${{ number_format($item->discount, 2) }}</td>
                <td style="text-align: right">${{ number_format($item->line_total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="total-row">
            <span>Subtotal:</span>
            <span>${{ number_format($transaction->subtotal, 2) }}</span>
        </div>
        @if($transaction->discount_amount > 0)
        <div class="total-row">
            <span>Discount:</span>
            <span>-${{ number_format($transaction->discount_amount, 2) }}</span>
        </div>
        @endif
        @if($transaction->tax_amount > 0)
        <div class="total-row">
            <span>Tax:</span>
            <span>${{ number_format($transaction->tax_amount, 2) }}</span>
        </div>
        @endif
        <div class="total-row grand-total">
            <span>TOTAL:</span>
            <span>${{ number_format($transaction->total_amount, 2) }}</span>
        </div>
    </div>

    <div class="footer">
        <p>Status: {{ strtoupper($transaction->status) }}</p>
        <p>Thank you for your purchase!</p>
        <p>Powered by POS System</p>
    </div>
</body>
</html>