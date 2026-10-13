<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_endpoint_creates_a_sandbox_snap_transaction(): void
    {
        config(['services.midtrans.server_key' => 'sandbox-server-key']);
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-demo',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/demo',
            ], 201),
        ]);

        [$user, $invoice] = $this->createInvoice();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/invoices/'.$invoice->id.'/pay');

        $response->assertOk()
            ->assertJsonPath('snap_token', 'snap-token-demo')
            ->assertJsonPath('snap_url', 'https://app.sandbox.midtrans.com/snap/v2/vtweb/demo');

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
            && $request['transaction_details']['gross_amount'] === 1000
            && $request['callbacks']['finish'] === 'http://localhost:5173/sales?payment=finish'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sandbox-server-key:'))
        );

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'snap_token' => 'snap-token-demo',
            'status' => 'pending',
        ]);
    }

    public function test_midtrans_notification_requires_a_valid_signature(): void
    {
        config(['services.midtrans.server_key' => 'sandbox-server-key']);
        [$user, $invoice] = $this->createInvoice();
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'gateway' => 'midtrans',
            'order_id' => 'NUSA-TEST-1',
            'status' => 'pending',
        ]);

        $payload = [
            'order_id' => $payment->order_id,
            'status_code' => '200',
            'gross_amount' => '1000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'invalid-signature',
        ];

        $this->postJson('/api/payments/midtrans/notification', $payload)
            ->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'unpaid']);
        $this->assertDatabaseHas('products', ['id' => $invoice->salesOrder->items->first()->product_id, 'stock' => 5]);
    }

    public function test_signed_settlement_marks_invoice_paid_and_deducts_stock_once(): void
    {
        config([
            'services.midtrans.server_key' => 'sandbox-server-key',
            'services.n8n.token' => 'test-automation-token',
            'services.n8n.webhooks.payment_success' => 'http://n8n.test/webhook/payment-success',
        ]);
        Http::fake(['http://n8n.test/*' => Http::response(['accepted' => true], 200)]);
        [, $invoice] = $this->createInvoice();
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'gateway' => 'midtrans',
            'order_id' => 'NUSA-TEST-2',
            'status' => 'pending',
        ]);

        $payload = [
            'order_id' => $payment->order_id,
            'status_code' => '200',
            'gross_amount' => '1000.00',
            'transaction_status' => 'settlement',
        ];
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key');

        $this->postJson('/api/payments/midtrans/notification', $payload)->assertOk()->assertJsonPath('status', 'ok');
        $this->postJson('/api/payments/midtrans/notification', $payload)->assertOk()->assertJsonPath('status', 'duplicate');

        Http::assertSent(fn ($request) =>
            $request->url() === 'http://n8n.test/webhook/payment-success'
            && $request['order_id'] === $payment->order_id
            && $request->hasHeader('X-Automation-Token', 'test-automation-token')
        );

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
        $this->assertDatabaseHas('products', ['id' => $invoice->salesOrder->items->first()->product_id, 'stock' => 3]);
    }

    public function test_signed_expiry_notification_marks_invoice_expired_without_changing_stock(): void
    {
        config(['services.midtrans.server_key' => 'sandbox-server-key']);
        [, $invoice] = $this->createInvoice();
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'gateway' => 'midtrans',
            'order_id' => 'NUSA-TEST-EXPIRED',
            'status' => 'pending',
        ]);
        $payload = [
            'order_id' => $payment->order_id,
            'status_code' => '407',
            'gross_amount' => '1000.00',
            'transaction_status' => 'expire',
        ];
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key');

        $this->postJson('/api/payments/midtrans/notification', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'expire');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'expired']);
        $this->assertDatabaseHas('products', ['id' => $invoice->salesOrder->items->first()->product_id, 'stock' => 5]);
    }

    private function createInvoice(): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Sandbox Customer', 'email' => 'buyer@example.test']);
        $product = Product::create([
            'sku' => 'SANDBOX-1',
            'name' => 'Demo Product',
            'price' => 500,
            'stock' => 5,
            'min_stock' => 1,
        ]);
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'total' => 1000,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 2,
            'price' => 500,
            'subtotal' => 1000,
        ]);
        $invoice = Invoice::create([
            'sales_order_id' => $order->id,
            'number' => 'INV-TEST-'.uniqid(),
            'amount' => 1000,
            'status' => 'unpaid',
        ]);

        return [$user, $invoice];
    }
}
