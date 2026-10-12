<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CommerceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_confirm_pickup_order_before_payment(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'K1', 'name' => 'Kho chính']);
        $product = DB::table('products')->insertGetId(['code' => 'XM-1', 'name' => 'Xi măng', 'unit' => 'bao', 'price' => 92000, 'direct_purchase_allowed' => true]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 3]);
        $orderId = app(CommerceWorkflow::class)->placePickupOrder($customer->id, [$product => 2], $warehouse);
        $debtId = DB::table('debts')->where('order_id', $orderId)->value('id');

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'awaiting_confirmation']);
        $this->actingAs($accountant)->get('/staff/work/finance')->assertOk()->assertDontSee('Ghi nhận</button>', false);
        $this->actingAs($accountant)->post('/staff/debts/'.$debtId.'/payments', ['amount' => 184000, 'method' => 'cash'])
            ->assertSessionHasErrors('workflow');
        $this->actingAs($sales)->get('/staff/work/sales_orders')->assertOk()
            ->assertSee('Xác nhận đơn')->assertSee('Xi măng');
        $this->actingAs($sales)->post('/staff/orders/'.$orderId.'/review', ['decision' => 'confirm'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'awaiting_payment', 'reviewed_by' => $sales->id]);
        $this->actingAs($accountant)->get('/staff/work/finance')->assertOk()->assertSee('Ghi nhận</button>', false);
        $itemId = DB::table('order_items')->where('order_id', $orderId)->value('id');
        app(CommerceWorkflow::class)->updateUnpaidPickupOrder($orderId, $customer->id, [$itemId => 1]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'awaiting_confirmation', 'reviewed_by' => null]);
        $this->actingAs($sales)->post('/staff/orders/'.$orderId.'/review', ['decision' => 'confirm'])->assertRedirect();
        $this->actingAs($accountant)->post('/staff/debts/'.$debtId.'/payments', ['amount' => 92000, 'method' => 'cash'])
            ->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'ready_to_issue']);
    }

    public function test_rejection_requires_reason_releases_stock_and_is_visible_to_customer(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $warehouseStaff = User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'K2', 'name' => 'Kho chính']);
        $product = DB::table('products')->insertGetId(['code' => 'G-1', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 1000, 'direct_purchase_allowed' => true]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 2]);
        $workflow = app(CommerceWorkflow::class);
        $orderId = $workflow->placePickupOrder($customer->id, [$product => 2], $warehouse);

        $this->actingAs($warehouseStaff)->post('/staff/orders/'.$orderId.'/review', ['decision' => 'confirm'])->assertForbidden();
        $this->actingAs($sales)->post('/staff/orders/'.$orderId.'/review', ['decision' => 'reject'])
            ->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'awaiting_confirmation']);
        $this->actingAs($sales)->post('/staff/orders/'.$orderId.'/review', ['decision' => 'reject', 'reason' => 'Cần xác minh số lượng'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'rejected', 'rejection_reason' => 'Cần xác minh số lượng']);
        $this->assertDatabaseHas('debts', ['order_id' => $orderId, 'amount' => 0, 'status' => 'settled']);
        $this->actingAs($customer)->get('/my/orders')->assertOk()->assertSee('Cần xác minh số lượng');
        $workflow->placePickupOrder($customer->id, [$product => 2], $warehouse);
        $this->assertDatabaseCount('orders', 2);
    }
}
