<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_used_by_a_transaction_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Customer']);
        $product = $this->createProduct();
        $order = SalesOrder::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'status' => 'draft', 'total' => 100]);
        SalesOrderItem::create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'qty' => 1, 'price' => 100, 'subtotal' => 100]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/products/'.$product->id)
            ->assertConflict();

        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $order->id, 'product_id' => $product->id]);
    }

    public function test_customer_with_sales_history_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Customer']);
        SalesOrder::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'status' => 'draft', 'total' => 100]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/customers/'.$customer->id)
            ->assertConflict();

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_supplier_with_purchase_history_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['name' => 'Supplier']);
        $product = $this->createProduct();
        $purchaseOrder = PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => 'draft', 'total' => 100]);
        PurchaseOrderItem::create(['purchase_order_id' => $purchaseOrder->id, 'product_id' => $product->id, 'qty' => 1, 'cost' => 100]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/suppliers/'.$supplier->id)
            ->assertConflict();

        $this->assertDatabaseHas('purchase_orders', ['id' => $purchaseOrder->id, 'supplier_id' => $supplier->id]);
    }

    private function createProduct(): Product
    {
        return Product::create([
            'sku' => uniqid('SKU-'),
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 1,
            'min_stock' => 0,
        ]);
    }
}
