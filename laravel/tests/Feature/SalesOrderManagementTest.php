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
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
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
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'awaiting_contract', 'reviewed_by' => $sales->id]);
        $this->actingAs($accountant)->post('/staff/debts/'.$debtId.'/payments', ['amount' => 184000, 'method' => 'cash'])
            ->assertSessionHasErrors('workflow');
        $itemId = DB::table('order_items')->where('order_id', $orderId)->value('id');
        $this->actingAs($customer)->put('/my/orders/'.$orderId, ['quantities' => [$itemId => 1]])
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'awaiting_contract']);
        $this->actingAs($accountant)->post('/staff/orders/'.$orderId.'/contracts', [
            'deposit_required' => 184000, 'payment_terms' => 'Thanh toán tại quầy',
            'delivery_terms' => 'Nhận tại kho',
        ])->assertForbidden();
        $this->actingAs($sales)->get('/staff/work/sales_orders')->assertOk()->assertSee('Lập hợp đồng từ đơn');
        $this->actingAs($sales)->post('/staff/orders/'.$orderId.'/contracts', [
            'deposit_required' => 184000, 'payment_terms' => 'Thanh toán đủ tại quầy',
            'delivery_terms' => 'Nhận tại kho',
        ])->assertRedirect();
        $contractId = DB::table('contracts')->where('order_id', $orderId)->value('id');
        $contractCode = DB::table('contracts')->where('id', $contractId)->value('code');
        $this->actingAs($customer)->get('/my/contracts')->assertOk()->assertSee('Chưa có hợp đồng');
        app(CommerceWorkflow::class)->submitContract($contractId);
        app(CommerceWorkflow::class)->reviewContract($contractId, $manager->id, true);
        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'order_id' => $orderId, 'status' => 'approved']);
        $this->assertSame(1, DB::table('contracts')->join('orders', 'orders.id', '=', 'contracts.order_id')
            ->where('contracts.id', $contractId)->where('orders.customer_id', $customer->id)->count());
        $this->actingAs($customer)->get('/my/contracts')->assertOk()
            ->assertSee($contractCode)->assertSee('Xi măng')->assertSee('DH-');
        app(CommerceWorkflow::class)->respondToContract($contractId, $customer->id, true);
        app(CommerceWorkflow::class)->signContract($contractId, 'contracts/pickup.pdf');
        $this->actingAs($accountant)->get('/staff/work/finance')->assertOk()->assertSee('Ghi nhận</button>', false);
        $this->actingAs($accountant)->post('/staff/debts/'.$debtId.'/payments', ['amount' => 92000, 'method' => 'cash'])
            ->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'awaiting_payment']);
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

    public function test_legacy_unsigned_contract_can_still_be_signed_without_duplicate_order(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $product = DB::table('products')->insertGetId(['code' => 'G-OLD', 'name' => 'Gạch cũ', 'unit' => 'viên', 'price' => 100]);
        $quoteId = app(CommerceWorkflow::class)->requestQuote($customer->id, [$product => 2], 'Công trình cũ');
        DB::table('quotes')->where('id', $quoteId)->update(['status' => 'accepted']);
        $contractId = DB::table('contracts')->insertGetId([
            'code' => 'HD-OLD', 'quote_id' => $quoteId, 'created_by' => $sales->id,
            'status' => 'approved', 'total' => 200, 'deposit_required' => 0,
            'customer_confirmed_at' => now(),
        ]);

        $this->actingAs($customer)->get('/my/contracts')->assertOk()->assertSee('HD-OLD');
        $orderId = app(CommerceWorkflow::class)->signContract($contractId, 'contracts/old.pdf');
        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'order_id' => $orderId, 'status' => 'signed']);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'contract_id' => $contractId, 'requires_contract' => false]);
        $this->assertDatabaseCount('orders', 1);
    }
}
