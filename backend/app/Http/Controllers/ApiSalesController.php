<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\MidtransService;
use App\Services\AutomationWebhookService;
use App\Services\StockService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ApiSalesController extends Controller
{
    public function index()
    {
        return response()->json(
            SalesOrder::with(['customer', 'items.product', 'invoice'])->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        $total = 0;
        $preparedItems = [];

        foreach ($validated['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            $subtotal = $product->price * $item['qty'];
            $total += $subtotal;
            $preparedItems[] = [
                'product_id' => $product->id,
                'qty' => $item['qty'],
                'price' => $product->price,
                'subtotal' => $subtotal,
            ];
        }

        $salesOrder = SalesOrder::create([
            'customer_id' => $validated['customer_id'],
            'user_id' => Auth::id(),
            'status' => 'draft',
            'total' => $total,
        ]);

        foreach ($preparedItems as $item) {
            $salesOrder->items()->create([
                'product_id' => $item['product_id'],
                'qty' => $item['qty'],
                'price' => $item['price'],
                'subtotal' => $item['subtotal'],
            ]);
        }

        $invoice = $this->createInvoiceRecord($salesOrder);

        return response()->json([
            'message' => 'Sales order berhasil dibuat.',
            'sales_order' => $salesOrder->load(['customer', 'items.product', 'invoice']),
            'invoice' => $invoice,
        ], 201);
    }

    public function show(SalesOrder $salesOrder)
    {
        return response()->json($salesOrder->load(['customer', 'items.product', 'invoice']));
    }

    public function createInvoice(SalesOrder $salesOrder)
    {
        return response()->json($this->createInvoiceRecord($salesOrder));
    }

    public function pay(Invoice $invoice, MidtransService $midtrans)
    {
        if ($invoice->status === 'paid') {
            return response()->json([
                'message' => 'Invoice sudah dibayar.',
                'invoice' => $invoice,
            ]);
        }

        $payment = Payment::firstOrNew(['invoice_id' => $invoice->id]);
        $savedSnapResponse = $payment->raw_payload['snap_response'] ?? null;

        if ($payment->status === 'pending' && $payment->snap_token && ! empty($savedSnapResponse['redirect_url'])) {
            return response()->json([
                'payment' => $payment,
                'snap_token' => $payment->snap_token,
                'snap_url' => $savedSnapResponse['redirect_url'],
            ]);
        }

        $orderId = 'NUSA-'.$invoice->id.'-'.Str::upper(Str::random(8));

        try {
            $snapResponse = $midtrans->createTransaction($invoice, $orderId);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => 'Midtrans Sandbox belum dikonfigurasi pada backend.',
            ], 503);
        } catch (ConnectionException|RequestException $exception) {
            return response()->json([
                'message' => 'Midtrans Sandbox gagal membuat transaksi. Periksa konfigurasi dan coba lagi.',
            ], 502);
        }

        if (empty($snapResponse['token']) || empty($snapResponse['redirect_url'])) {
            return response()->json([
                'message' => 'Respons transaksi dari Midtrans tidak lengkap.',
            ], 502);
        }

        $payment->fill([
            'gateway' => 'midtrans',
            'order_id' => $orderId,
            'snap_token' => $snapResponse['token'],
            'status' => 'pending',
            'raw_payload' => ['snap_response' => $snapResponse],
        ])->save();

        return response()->json([
            'payment' => $payment,
            'snap_token' => $payment->snap_token,
            'snap_url' => $snapResponse['redirect_url'],
        ]);
    }

    public function midtransNotification(Request $request, AutomationWebhookService $automationWebhook)
    {
        $payload = $request->all();
        $orderId = $payload['order_id'] ?? null;
        $serverKey = config('services.midtrans.server_key');
        $signature = $payload['signature_key'] ?? '';
        $statusCode = $payload['status_code'] ?? null;
        $grossAmount = $payload['gross_amount'] ?? null;

        if (! $orderId || ! $statusCode || ! $grossAmount || ! $serverKey || ! $signature) {
            return response()->json(['message' => 'Notifikasi Midtrans tidak lengkap atau belum dikonfigurasi.'], 400);
        }

        $expectedSignature = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['message' => 'Signature notifikasi Midtrans tidak valid.'], 403);
        }

        $payment = Payment::where('order_id', $orderId)->first();

        if (! $payment) {
            return response()->json(['message' => 'Pembayaran tidak ditemukan.'], 404);
        }

        $status = $payload['transaction_status'] ?? 'pending';
        $isSettled = $status === 'settlement'
            || ($status === 'capture' && ($payload['fraud_status'] ?? null) === 'accept');

        return DB::transaction(function () use ($payment, $payload, $status, $isSettled, $grossAmount, $automationWebhook) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $invoice = $payment->invoice()->lockForUpdate()->firstOrFail();

            if ($invoice->status === 'paid') {
                return response()->json(['status' => 'duplicate']);
            }

            if (! $isSettled) {
                $payment->update([
                    'status' => $status,
                    'raw_payload' => array_merge($payment->raw_payload ?? [], ['notification' => $payload]),
                ]);

                if ($status === 'expire' && $invoice->status !== 'paid') {
                    $invoice->update(['status' => 'expired']);
                }

                return response()->json(['status' => $status]);
            }

            if (number_format((float) $invoice->amount, 2, '.', '') !== number_format((float) $grossAmount, 2, '.', '')) {
                return response()->json(['message' => 'Jumlah pembayaran Midtrans tidak sesuai invoice.'], 422);
            }

            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $payment->update([
                'status' => 'paid',
                'raw_payload' => array_merge($payment->raw_payload ?? [], ['notification' => $payload]),
                'paid_at' => now(),
            ]);

            $invoice->salesOrder->update(['status' => 'paid']);
            app(StockService::class)->applyInvoiceSettlement($invoice);

            $eventPayload = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'order_id' => $payment->order_id,
                'amount' => (float) $invoice->amount,
                'customer' => $invoice->salesOrder->customer()->value('name'),
            ];
            DB::afterCommit(fn () => $automationWebhook->dispatch('payment_success', $eventPayload));

            return response()->json([
                'status' => 'ok',
                'invoice' => $invoice->fresh(),
            ]);
        });
    }

    protected function createInvoiceRecord(SalesOrder $salesOrder): Invoice
    {
        if ($salesOrder->invoice) {
            return $salesOrder->invoice;
        }

        $invoice = Invoice::create([
            'sales_order_id' => $salesOrder->id,
            'number' => 'INV-'.str_pad((string) $salesOrder->id, 6, '0', STR_PAD_LEFT).'-'.date('Ymd'),
            'amount' => $salesOrder->total,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
        ]);

        return $invoice;
    }
}
