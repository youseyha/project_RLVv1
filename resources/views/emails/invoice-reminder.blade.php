<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: #FF9800;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background: #f9f9f9;
            padding: 30px;
            border: 1px solid #ddd;
        }
        .reminder-box {
            background: #fff3e0;
            padding: 20px;
            margin: 20px 0;
            border-left: 5px solid #FF9800;
            border-radius: 5px;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background: #FF9800;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔔 Payment Reminder</h1>
            <p>{{ $invoice->invoice_number }}</p>
        </div>

        <div class="content">
            <p>Hello {{ $tenant->company_name }},</p>
            
            <p>This is a friendly reminder that your invoice payment is due soon.</p>

            <div class="reminder-box">
                <h3 style="margin-top: 0;">Invoice Details</h3>
                <p><strong>Invoice Number:</strong> {{ $invoice->invoice_number }}</p>
                <p><strong>Due Date:</strong> {{ $invoice->due_date->format('F d, Y') }}</p>
                @if($daysUntilDue > 0)
                    <p><strong>Days Until Due:</strong> {{ $daysUntilDue }} days</p>
                @else
                    <p style="color: #F44336;"><strong>Status:</strong> Due today!</p>
                @endif
                <p><strong>Amount Due:</strong> ${{ number_format($invoice->amount_due, 2) }}</p>
            </div>

            <center>
                <a href="{{ config('app.url') }}/invoices/{{ $invoice->invoice_id }}/pay" class="button">
                    View & Pay Invoice
                </a>
            </center>

            <p>To avoid any service interruption, please ensure payment is made by the due date.</p>

            <p>If you have any questions or need assistance, please contact us.</p>

            <p>Thank you for your business!</p>

            <p>Best regards,<br>
            <strong>{{ config('app.name') }} Team</strong></p>
        </div>

        <div class="footer">
            <p>This is an automated reminder. Please do not reply.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>