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
            background: #F44336;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .warning-icon {
            font-size: 48px;
        }
        .content {
            background: #fff3cd;
            padding: 30px;
            border: 2px solid #F44336;
        }
        .alert-box {
            background: #ffebee;
            padding: 20px;
            margin: 20px 0;
            border-left: 5px solid #F44336;
            border-radius: 5px;
        }
        .amount {
            font-size: 32px;
            color: #F44336;
            font-weight: bold;
        }
        .button {
            display: inline-block;
            padding: 15px 40px;
            background: #F44336;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
            font-size: 18px;
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
            <div class="warning-icon">⚠️</div>
            <h1>PAYMENT OVERDUE</h1>
            <p>{{ $invoice->invoice_number }}</p>
        </div>

        <div class="content">
            <p><strong>Dear {{ $tenant->company_name }},</strong></p>
            
            <p>This is an urgent notice regarding your overdue invoice.</p>

            <div class="alert-box">
                <h2 style="margin-top: 0; color: #F44336;">Invoice Details</h2>
                <p><strong>Invoice Number:</strong> {{ $invoice->invoice_number }}</p>
                <p><strong>Original Due Date:</strong> {{ $invoice->due_date->format('F d, Y') }}</p>
                <p><strong>Days Overdue:</strong> <span style="color: #F44336; font-weight: bold;">{{ $daysOverdue }} days</span></p>
                <p><strong>Amount Due:</strong></p>
                <div class="amount">${{ number_format($amountDue, 2) }}</div>
            </div>

            <p><strong>Immediate Action Required:</strong></p>
            <ul>
                <li>Your account may be suspended if payment is not received within 3 days</li>
                <li>Late fees may apply after 7 days overdue</li>
                <li>Service interruption may occur for unpaid invoices</li>
            </ul>

            <center>
                <a href="{{ config('app.url') }}/invoices/{{ $invoice->invoice_id }}/pay" class="button">
                    PAY NOW
                </a>
            </center>

            <p>If you have already made this payment, please disregard this notice and contact us with your payment reference.</p>

            <p>If you're experiencing difficulty with payment, please contact our billing department immediately to discuss payment arrangements.</p>

            <p><strong>Contact Us:</strong><br>
            Email: billing@{{ config('app.url') }}<br>
            Phone: +855 12 345 678</p>

            <p>Thank you for your prompt attention to this matter.</p>

            <p>Best regards,<br>
            <strong>{{ config('app.name') }} Billing Team</strong></p>
        </div>

        <div class="footer">
            <p>This is an automated reminder. Please do not reply.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>