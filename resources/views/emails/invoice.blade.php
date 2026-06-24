<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f5f5f5; padding:20px;">

<div style="max-width:900px;margin:auto;background:#ffffff;border:1px solid #ddd;">

    <!-- HEADER -->
    <div style="background:#f7f3f0;padding:30px;">
        <table width="100%">
            <tr>
                <td>
                    <h1 style="margin:0;color:#222;">
                        {{ config('app.name') }}
                    </h1>
                    <p style="margin:5px 0;color:#666;">
                        SaaS POS Solution
                    </p>
                </td>

                <td align="right">
                    <strong>{{ config('app.name') }}</strong><br>
                    Phnom Penh, Cambodia<br>
                    support@yourcompany.com<br>
                    +855 12 345 678
                </td>
            </tr>
        </table>
    </div>

    <!-- TITLE -->
    <div style="padding:25px;">
        <h2 style="margin:0;color:#2c3e50;">
            INVOICE
        </h2>
    </div>

    <!-- CUSTOMER INFO -->
    <div style="padding:0 25px 25px 25px;">
        <table width="100%">
            <tr>
                <td width="50%" valign="top">
                    <table width="100%">
                        <tr>
                            <td>Company</td>
                            <td>{{ $tenant->company_name }}</td>
                        </tr>

                        <tr>
                            <td>Address</td>
                            <td>{{ $tenant->address ?? 'N/A' }}</td>
                        </tr>

                        <tr>
                            <td>Phone</td>
                            <td>{{ $tenant->phone ?? 'N/A' }}</td>
                        </tr>

                        <tr>
                            <td>Email</td>
                            <td>{{ $tenant->email ?? 'N/A' }}</td>
                        </tr>
                    </table>
                </td>

                <td width="50%" valign="top">
                    <table width="100%"> 
                        <tr>
                            <td>Invoice #</td>
                            <td>{{ $invoice->invoice_number }}</td>
                        </tr>

                        <tr>
                            <td>Date</td>
                            <td>{{ $invoice->invoice_date->format('d M Y') }}</td>
                        </tr>

                        <tr>
                            <td>Due Date</td>
                            <td>{{ $invoice->due_date->format('d M Y') }}</td>
                        </tr>

                        <tr>
                            <td>Status</td>
                            <td>{{ strtoupper($invoice->status) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <!-- ITEMS -->
    <div style="padding:0 25px;">

        <table width="100%"
               cellpadding="10"
               cellspacing="0"
               style="border-collapse:collapse;">

            <thead>
                <tr style="background:#f8f8f8;border-top:2px solid #f39c12;">
                    <th align="left">PRODUCT / SERVICE</th>
                    <th align="center">QTY</th>
                    <th align="right">UNIT PRICE</th>
                    <th align="right">TOTAL</th>
                </tr>
            </thead>

            <tbody>

            @foreach($invoice->items as $item)

                <tr style="border-bottom:1px solid #eee;">
                    <td>
                        <strong>{{ $item->description }}</strong>
                    </td>

                    <td align="center">
                        {{ $item->quantity }}
                    </td>

                    <td align="right">
                        ${{ number_format($item->unit_price, 2) }}
                    </td>

                    <td align="right">
                        ${{ number_format($item->line_total, 2) }}
                    </td>
                </tr>

            @endforeach

            </tbody>

        </table>

    </div>

    <!-- TOTAL -->
    <div style="padding:30px 25px;">

        <table width="350" align="right">

            <tr>
                <td>Subtotal</td>
                <td align="right">
                    ${{ number_format($invoice->subtotal,2) }}
                </td>
            </tr>

            <tr>
                <td>Tax</td>
                <td align="right">
                    ${{ number_format($invoice->tax_amount,2) }}
                </td>
            </tr>

            <tr>
                <td>Discount</td>
                <td align="right">
                    ${{ number_format($invoice->discount_amount ?? 0,2) }}
                </td>
            </tr>

            <tr>
                <td><strong>Total</strong></td>
                <td align="right">
                    <strong>
                        ${{ number_format($invoice->total_amount,2) }}
                    </strong>
                </td>
            </tr>

            <tr>
                <td>Amount Paid</td>
                <td align="right">
                    ${{ number_format($invoice->amount_paid,2) }}
                </td>
            </tr>

            <tr>
                <td style="padding-top:10px;">
                    <strong style="color:#f39c12;">
                        Amount Due
                    </strong>
                </td>

                <td align="right" style="padding-top:10px;">
                    <strong style="font-size:20px;color:#f39c12;">
                        ${{ number_format($invoice->amount_due,2) }}
                    </strong>
                </td>
            </tr>

        </table>

        <div style="clear:both;"></div>

    </div>

    <!-- PAY BUTTON -->
    @if($invoice->status !== 'paid')

    <div style="text-align:center;padding:20px;">

        <a href="{{ config('app.frontend_url') }}/billing/invoices/{{ $invoice->invoice_id }}"
           style="
                background:#f39c12;
                color:#fff;
                padding:15px 30px;
                text-decoration:none;
                border-radius:5px;
                font-weight:bold;
           ">
            PAY NOW
        </a>

    </div>

    @endif

    <!-- NOTES -->
    <div style="padding:30px;border-top:1px solid #eee;">

        <table width="100%">
            <tr>

                <td width="50%" valign="top">

                    <h4>Invoice Notes</h4>

                    <p style="font-size:13px;color:#666;">
                        Thank you for using our SaaS POS platform.
                        Payment is due within 7 days.
                    </p>

                </td>

                <td width="50%" valign="top">

                    <h4>Terms & Conditions</h4>

                    <p style="font-size:13px;color:#666;">
                        If payment is not received by the due date,
                        the subscription may be suspended automatically.
                    </p>

                </td>

            </tr>
        </table>

    </div>

    <!-- FOOTER -->
    <div style="
        background:#f8f8f8;
        text-align:center;
        padding:20px;
        color:#888;
        font-size:12px;
    ">
        © {{ date('Y') }} {{ config('app.name') }}
        <br>
        support@yourcompany.com
    </div>

</div>

</body>
</html>