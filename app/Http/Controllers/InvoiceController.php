<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\InvoicePdfService;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\InvoiceCollection;
use App\Http\Resources\PaymentResource;
use App\Models\Subscription;
use Illuminate\Http\Request;
use App\Mail\InvoiceMail;
use App\Mail\InvoiceOverdueMail;
use App\Mail\InvoiceReminderMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceController extends Controller
{
    protected $invoiceService;
    protected $pdfService;

    public function __construct(
        InvoiceService $invoiceService,
        InvoicePdfService $pdfService
    ) {
        $this->invoiceService = $invoiceService;
        $this->pdfService = $pdfService;
    }
    /**
     * បញ្ជី invoices
     */
    public function index(Request $request)
    {
        $tenant = app('tenant');

        $invoices = Invoice::with(['subscription.plan', 'items', 'payments'])    
            ->where('tenant_id', $tenant->tenant_id)
            ->when($request->status, fn($q, $status) => 
                $q->where('status', $status))
            ->when($request->date_from, fn($q, $date) => 
                $q->whereDate('invoice_date', '>=', $date))
            ->when($request->date_to, fn($q, $date) => 
                $q->whereDate('invoice_date', '<=', $date))
            ->when($request->search, fn($q, $search) => 
                $q->where('invoice_number', 'LIKE', "%{$search}%"))
            ->latest('invoice_date')
            ->paginate($request->per_page ?? 20);

        return new InvoiceCollection($invoices);
    }

    /**
     * មើល invoice តែមួយ
     */
    public function show(string $id)
    {
        $tenant = app('tenant');

        $invoice = Invoice::with(['subscription.plan', 'items', 'payments.method', 'tenant'])
            ->where('tenant_id', $tenant->tenant_id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new InvoiceResource($invoice),
        ]);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * CREATE INVOICE (បង្កើត invoice)
     * ════════════════════════════════════════════════════════════
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subscription_id' => 'required|uuid|exists:subscriptions,subscription_id',
        ]);

        $tenant = app('tenant');
        
        $subscription = Subscription::where('tenant_id', $tenant->tenant_id)
            ->findOrFail($validated['subscription_id']);

        try {
            $invoice = $this->invoiceService->generateSubscriptionInvoice($subscription);

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => new InvoiceResource($invoice),
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create invoice', [
                'subscription_id' => $validated['subscription_id'],
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * SEND INVOICE EMAIL (ផ្ញើ invoice តាម email)
     * ════════════════════════════════════════════════════════════
     */
    public function send(string $id)
    {
        $tenant = app('tenant');
        
        $invoice = Invoice::with(['subscription.plan', 'items', 'tenant'])
            ->where('tenant_id', $tenant->tenant_id)
            ->findOrFail($id);

        try {
            // Send invoice email
            Mail::to($invoice->tenant->email)
                ->cc(config('invoice.email.cc') ? explode(',', config('invoice.email.cc')) : [])
                ->bcc(config('invoice.email.bcc') ? explode(',', config('invoice.email.bcc')) : [])
                ->send(new InvoiceMail($invoice));

            // Update status if draft
            if ($invoice->status === 'draft') {
                $invoice->update(['status' => 'sent']);
            }

            Log::info('Invoice email sent', [
                'invoice_id' => $invoice->invoice_id,
                'invoice_number' => $invoice->invoice_number,
                'tenant' => $invoice->tenant->company_name,
                'recipient' => $invoice->tenant->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice sent successfully to ' . $invoice->tenant->email,
                'data' => new InvoiceResource($invoice->fresh()),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send invoice email', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send invoice: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * លុបចោល invoice
     */
    public function cancel(string $id)
    {
        $tenant = app('tenant');
        $invoice = Invoice::where('tenant_id', $tenant->tenant_id)->findOrFail($id);

        try {
            $invoice = $this->invoiceService->cancelInvoice($invoice);

            return response()->json([
                'success' => true,
                'message' => 'Invoice cancelled successfully',
                'data' => new InvoiceResource($invoice),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * SEND INVOICE REMINDER
     */
    public function sendReminder(string $id)
    {
        $tenant = app('tenant');
        
        $invoice = Invoice::with(['subscription.plan', 'tenant'])
                   ->where('tenant_id', $tenant->tenant_id)->findOrFail($id);

        try {
            // Validate invoice status
            if (!in_array($invoice->status, ['sent', 'overdue','pending'])) {
                throw new \Exception('Can only send reminders for sent or overdue invoices');
            }

            // Determine which email to send
            if ($invoice->status === 'overdue') {
                $mail = new InvoiceOverdueMail($invoice);
            } else {
                $mail = new InvoiceReminderMail($invoice);
            }

            // Send email
            Mail::to($invoice->tenant->email)
                ->cc(config('invoice.email.cc') ? explode(',', config('invoice.email.cc')) : [])
                ->send($mail);

            Log::info('Invoice reminder sent', [
                'invoice_id' => $invoice->invoice_id,
                'status' => $invoice->status,
                'recipient' => $invoice->tenant->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Reminder sent successfully',
                'data' => new InvoiceResource($invoice->fresh()),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send invoice reminder', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send reminder: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download PDF
     */
    public function downloadPdf(string $id)
    {
        $tenant = app('tenant');
        $invoice = Invoice::with(['subscription.plan', 'items', 'tenant'])
                    ->where('tenant_id', $tenant->tenant_id)
                    ->findOrFail($id);

        return $this->pdfService->download($invoice);
    }

    /**
     * មើល PDF
     */
    public function viewPdf(string $id)
    {
        $tenant = app('tenant');
        $invoice = Invoice::with(['subscription.plan', 'items', 'tenant'])
            ->where('tenant_id', $tenant->tenant_id)
            ->findOrFail($id);

        return $this->pdfService->stream($invoice);
    }

    /**
     * ════════════════════════════════════════════════════════════
     * RECORD PAYMENT (ទូទាត់ប្រាក់)
     * ════════════════════════════════════════════════════════════
     */
    public function pay(Request $request, string $id)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|uuid|exists:payment_methods,method_id',
            'gateway_id' => 'nullable|uuid|exists:payment_gateways,gateway_id',
            'payment_reference' => 'nullable|string|max:100',
        ]);

        $tenant = app('tenant');
        $invoice = Invoice::where('tenant_id', $tenant->tenant_id)->findOrFail($id);

        try {
            $payment = $this->invoiceService->recordPayment(
                invoice: $invoice,
                amount: $validated['amount'],
                paymentMethodId: $validated['payment_method_id'],
                gatewayId: $validated['gateway_id'] ?? null,
                paymentReference: $validated['payment_reference'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'data' => [
                    'payment' => new PaymentResource($payment),
                    'invoice' => new InvoiceResource($invoice->fresh()),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * PROCESS REFUND (សងប្រាក់វិញ)
     * ════════════════════════════════════════════════════════════
     */
    public function refund(Request $request, string $id)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|uuid|exists:payment_methods,method_id',
            'gateway_id' => 'nullable|uuid|exists:payment_gateways,gateway_id',
            'reason' => 'nullable|string',
        ]);

        $tenant = app('tenant');
        $invoice = Invoice::where('tenant_id', $tenant->tenant_id)->findOrFail($id);

        try {
            $refund = $this->invoiceService->processRefund(
                invoice: $invoice,
                refundAmount: $validated['amount'],
                paymentMethodId: $validated['payment_method_id'],
                gatewayId: $validated['gateway_id'] ?? null,
                reason: $validated['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Refund processed successfully',
                'data' => [
                    'refund' => new PaymentResource($refund),
                    'invoice' => new InvoiceResource($invoice->fresh()),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * ════════════════════════════════════════════════════════════
     * GET PAYMENT HISTORY (ប្រវត្តិការទូទាត់)
     * ════════════════════════════════════════════════════════════
     */
    public function paymentHistory(string $id)
    {
        $tenant = app('tenant');
        $invoice = Invoice::where('tenant_id', $tenant->tenant_id)->findOrFail($id);

        $summary = $this->invoiceService->getPaymentSummary($invoice);

        return response()->json([
            'success' => true,
            'data' => [
                'invoice_id' => $invoice->invoice_id,
                'invoice_number' => $invoice->invoice_number,
                'total_amount' => $invoice->total_amount,
                'amount_paid' => $invoice->amount_paid,
                'amount_due' => $invoice->amount_due,
                'status' => $invoice->status,
                'summary' => [
                    'total_paid' => $summary['total_paid'],
                    'total_refunded' => $summary['total_refunded'],
                    'net_paid' => $summary['net_paid'],
                    'payment_count' => $summary['payment_count'],
                    'refund_count' => $summary['refund_count'],
                ],
                'payments' => PaymentResource::collection($summary['payments']),
            ],
        ]);
    }
    /**
     * ════════════════════════════════════════════════════════════
     * INVOICE STATISTICS (ស្ថិតិ invoices)
     * ════════════════════════════════════════════════════════════
     */
    public function statistics(Request $request)
    {
        $tenant = app('tenant');

        $query = Invoice::where('tenant_id', $tenant->tenant_id);

        if ($request->date_from) {
            $query->whereDate('invoice_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('invoice_date', '<=', $request->date_to);
        }

        $stats = [
            'total_invoices' => (clone $query)->count(),
            'total_amount' => (clone $query)->sum('total_amount'),
            'total_paid' => (clone $query)->sum('amount_paid'),
            'total_due' => (clone $query)->sum('amount_due'),
            
            'by_status' => [
                'draft' => (clone $query)->where('status', 'draft')->count(),
                'sent' => (clone $query)->where('status', 'sent')->count(),
                'paid' => (clone $query)->where('status', 'paid')->count(),
                'overdue' => (clone $query)->where('status', 'overdue')->count(),
                'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
                'pending' => (clone $query)->where('status', 'pending')->count(),
            ],
            
            'overdue_amount' => (clone $query)
                ->where('status', 'overdue')
                ->sum('amount_due'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}