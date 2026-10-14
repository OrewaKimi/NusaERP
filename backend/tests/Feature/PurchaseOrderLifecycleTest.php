<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PurchaseOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_cannot_be_received_before_approval(): void
    {
        $user = User::factory()->create(['role' => 'gudang']);
        $purchaseOrder = $this->createPurchaseOrder('submitted');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchase-orders/'.$purchaseOrder->id.'/receive')
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $purchaseOrder->items->first()->product_id, 'stock' => 4]);
        $this->assertDatabaseMissing('stock_movements', ['ref_type' => 'purchase_order', 'ref_id' => $purchaseOrder->id]);
    }

    public function test_approved_purchase_order_can_be_received_once_only(): void
    {
        $user = User::factory()->create(['role' => 'gudang']);
        $purchaseOrder = $this->createPurchaseOrder('approved');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchase-orders/'.$purchaseOrder->id.'/receive')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', 'received');

        $this->postJson('/api/purchase-orders/'.$purchaseOrder->id.'/receive')->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $purchaseOrder->items->first()->product_id, 'stock' => 7]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_automation_can_approve_only_submitted_purchase_orders(): void
    {
        config(['services.n8n.token' => 'test-automation-token']);
        $purchaseOrder = $this->createPurchaseOrder('draft');

        $this->postJson('/api/automation/po/'.$purchaseOrder->id.'/approve', [], [
            'X-Automation-Token' => 'test-automation-token',
        ])->assertStatus(409);

        $purchaseOrder->update(['status' => 'submitted']);

        $this->postJson('/api/automation/po/'.$purchaseOrder->id.'/approve', [], [
            'X-Automation-Token' => 'test-automation-token',
        ])->assertOk()->assertJsonPath('purchase_order.status', 'approved');
    }

    public function test_admin_can_approve_a_submitted_purchase_order_through_erp_api(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $purchaseOrder = $this->createPurchaseOrder('submitted');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/purchase-orders/'.$purchaseOrder->id.'/approve')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', 'approved');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrder->id,
            'status' => 'approved',
        ]);
    }

    public function test_submitting_a_purchase_order_dispatches_the_n8n_event(): void
    {
        config([
            'services.n8n.token' => 'test-automation-token',
            'services.n8n.webhooks.po_submitted' => 'http://n8n.test/webhook/po-submitted',
        ]);
        Http::fake(['http://n8n.test/*' => Http::response(['accepted' => true], 200)]);
        $user = User::factory()->create(['role' => 'gudang']);
        $purchaseOrder = $this->createPurchaseOrder('draft');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchase-orders/'.$purchaseOrder->id.'/submit')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', 'submitted');

        Http::assertSent(fn ($request) =>
            $request->url() === 'http://n8n.test/webhook/po-submitted'
            && $request['purchase_order_id'] === $purchaseOrder->id
            && $request->hasHeader('X-Automation-Token', 'test-automation-token')
        );
        $this->assertDatabaseHas('automation_logs', ['event' => 'po_submitted', 'status' => 'sent']);
    }

    private function createPurchaseOrder(string $status): PurchaseOrder
    {
        $supplier = Supplier::create(['name' => 'Lifecycle Supplier']);
        $product = Product::create([
            'sku' => 'PO-'.uniqid(),
            'name' => 'Lifecycle Product',
            'price' => 100,
            'stock' => 4,
            'min_stock' => 1,
        ]);
        $purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'status' => $status,
            'total' => 300,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'qty' => 3,
            'cost' => 100,
        ]);

        return $purchaseOrder->load('items');
    }
}
