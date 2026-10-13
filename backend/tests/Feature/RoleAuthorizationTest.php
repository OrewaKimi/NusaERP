<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_create_sales_orders_but_cannot_mutate_inventory(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $customer = Customer::create(['name' => 'Sales Customer']);
        $product = $this->createProduct();

        $this->actingAs($sales, 'sanctum')
            ->postJson('/api/sales-orders', [
                'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'qty' => 1]],
            ])->assertCreated();

        $this->postJson('/api/products', [
            'sku' => 'SALES-FORBIDDEN',
            'name' => 'Forbidden Product',
            'price' => 100,
            'stock' => 1,
            'min_stock' => 0,
        ])->assertForbidden();
    }

    public function test_warehouse_user_can_manage_products_but_cannot_approve_purchase_orders(): void
    {
        $warehouse = User::factory()->create(['role' => 'gudang']);

        $response = $this->actingAs($warehouse, 'sanctum')
            ->postJson('/api/products', [
                'sku' => 'WAREHOUSE-1',
                'name' => 'Warehouse Product',
                'price' => 100,
                'stock' => 1,
                'min_stock' => 0,
            ])->assertCreated();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $response->json('id'),
            'type' => 'in',
            'qty' => 1,
            'ref_type' => 'opening_stock',
        ]);

        $supplier = \App\Models\Supplier::create(['name' => 'Role Supplier']);
        $purchaseOrder = \App\Models\PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => 'submitted', 'total' => 0]);
        $this->postJson('/api/purchase-orders/'.$purchaseOrder->id.'/approve')->assertForbidden();
    }

    public function test_warehouse_user_can_adjust_stock_and_cannot_reduce_it_below_zero(): void
    {
        $warehouse = User::factory()->create(['role' => 'gudang']);
        $product = $this->createProduct();

        $this->actingAs($warehouse, 'sanctum')
            ->postJson('/api/products/'.$product->id.'/adjust-stock', [
                'direction' => 'out',
                'qty' => 2,
                'note' => 'Stock count correction',
            ])->assertOk()->assertJsonPath('product.stock', 3);

        $this->postJson('/api/products/'.$product->id.'/adjust-stock', [
            'direction' => 'out',
            'qty' => 4,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjust',
            'qty' => 2,
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 3]);
    }

    public function test_changing_stock_from_product_form_creates_a_movement_record(): void
    {
        $warehouse = User::factory()->create(['role' => 'gudang']);
        $product = $this->createProduct();

        $this->actingAs($warehouse, 'sanctum')
            ->putJson('/api/products/'.$product->id, ['stock' => 8])
            ->assertOk()
            ->assertJsonPath('stock', 8);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjust',
            'qty' => 3,
        ]);
    }

    private function createProduct(): Product
    {
        return Product::create([
            'sku' => 'ROLE-'.uniqid(),
            'name' => 'Role Product',
            'price' => 100,
            'stock' => 5,
            'min_stock' => 1,
        ]);
    }
}
