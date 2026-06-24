<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $invoice->invoice_number }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Play:wght@400;700&display=swap');
        body {
            font-family: "Play", sans-serif;
            font-weight: 400;
            font-style: normal;
            font-size: 14px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #000;
            padding-bottom: 20px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .invoice-title {
            font-size: 20px;
            margin: 20px 0;
        }
        .info-section {
            margin-bottom: 30px;
        }
        .info-row {
            margin-bottom: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .text-right {
            text-align: right;
        }
        .total-section {
            width: 200px;
            margin-left: auto;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }
        .total-label {
            font-weight: bold;
        }
        .grand-total {
            font-size: 18px;
            font-weight: bold;
            border-top: 2px solid #000;
            padding-top: 10px;
            margin-top: 10px;
        }
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 5px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 12px;
        }
        .status-paid { background-color: #4CAF50; color: white; }
        .status-pending { background-color: #FFC107; color: black; }
        .status-overdue { background-color: #F44336; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">{{ config('app.name') }}</div>
        <div>SaaS POS System</div>
    </div>

    <div class="invoice-title">
        INVOICE #{{ $invoice->invoice_number }}
    </div>

    <div class="info-section">
        <div class="info-row"><strong>Bill To:</strong> {{ $invoice->tenant->company_name }}</div>
        <div class="info-row"><strong>Email:</strong> {{ $invoice->tenant->email }}</div>
        <div class="info-row"><strong>Issue Date:</strong> {{ $invoice->invoice_date->format('Y-m-d') }}</div>
        <div class="info-row"><strong>Due Date:</strong> {{ $invoice->due_date->format('Y-m-d') }}</div>
        <div class="info-row"><strong>Status:</strong> 
            <span class="status-badge status-{{ $invoice->status }}">
                {{ strtoupper($invoice->status) }}
            </span>
        </div>
    </div>
    </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Quantity</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="text-right">{{ number_format($item->quantity, 2) }}</td>
                <td class="text-right">${{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right">${{ number_format($item->amount, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-section">
        <div class="total-row">
            <span>Subtotal:</span>
            <span>${{ number_format($invoice->subtotal, 2) }}</span>
        </div>
        @if($invoice->discount_amount > 0)
        <div class="total-row">
            <span>Discount:</span>
            <span>-${{ number_format($invoice->discount_amount, 2) }}</span>
        </div>
        @endif
        @if($invoice->tax_amount > 0)
        <div class="total-row">
            <span>Tax (10%):</span>
            <span>${{ number_format($invoice->tax_amount, 2) }}</span>
        </div>
        @endif
        <div class="total-row grand-total">
            <span>Total:</span>
            <span>${{ number_format($invoice->total_amount, 2) }}</span>
        </div>
        @if($invoice->paid_amount > 0)
        <div class="total-row">
            <span>Paid:</span>
            <span>-${{ number_format($invoice->paid_amount, 2) }}</span>
        </div>
        <div class="total-row grand-total">
            <span>Balance:</span>
            <span>${{ number_format($invoice->balance, 2) }}</span>
        </div>
        @endif
    </div>

    @if($invoice->notes)
    <div style="margin-top: 30px;">
        <strong>Notes:</strong><br>
        {{ $invoice->notes }}
    </div>
    @endif

    <div class="footer">
        <p>Thank you for your business!</p>
        <p>Generated on {{ now()->format('Y-m-d H:i:s') }}</p>
    </div>
</body>
</html>