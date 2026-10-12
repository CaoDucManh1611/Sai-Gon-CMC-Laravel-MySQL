<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CommerceWorkflow;
use App\Services\FulfillmentWorkflow;
use App\Support\UiFormat;
use Database\Seeders\DemoOpeningStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommerceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantity_input_uses_plain_decimal_without_thousands_separator(): void
    {
        $this->assertSame('3', UiFormat::inputQuantity('3.000'));
        $this->assertSame('1000', UiFormat::inputQuantity('1000.000'));
        $this->assertSame('1.25', UiFormat::inputQuantity('1.250'));
    }

    public function test_customer_edits_and_withdraws_only_unprocessed_quote(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $product = DB::table('products')->insertGetId(['code' => 'Q-EDIT', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 1000]);
        $workflow = app(CommerceWorkflow::class);
        $quoteId = $workflow->requestQuote($customer->id, [$product => 2], 'Công trình A');
        $itemId = DB::table('quote_items')->where('quote_id', $quoteId)->value('id');

        $this->actingAs($other)->put('/my/quotes/'.$quoteId, [
            'delivery_address' => 'Công trình B', 'quantities' => [$itemId => 3],
        ])->assertSessionHasErrors('workflow');
        $this->actingAs($customer)->put('/my/quotes/'.$quoteId, [
            'delivery_address' => 'Công trình B', 'note' => 'Gọi trước khi giao', 'quantities' => [$itemId => 3],
        ])->assertRedirect();
        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'delivery_address' => 'Công trình B', 'total' => 3000]);
        $this->assertDatabaseHas('quote_items', ['id' => $itemId, 'quantity' => 3, 'line_total' => 3000]);
        $this->get('/my/quotes')->assertOk()->assertSee('order-product')->assertSee('Gạch × 3')
            ->assertSee('Sửa yêu cầu')->assertSee('Rút yêu cầu');
        $this->get('/my/quotes/'.$quoteId.'?edit=1')->assertOk()->assertSee('quote-product')
            ->assertSee('Chỉnh sửa yêu cầu báo giá')->assertSee('value="3"', false)
            ->assertDontSee('value="3.000"', false);
        $this->actingAs($customer)->post('/my/quotes/'.$quoteId.'/withdraw')->assertRedirect('/my/quotes');
        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'status' => 'cancelled']);
        $this->actingAs($customer)->put('/my/quotes/'.$quoteId, [
            'delivery_address' => 'Công trình C', 'quantities' => [$itemId => 4],
        ])->assertSessionHasErrors('workflow');
    }

    public function test_customer_can_edit_unpaid_pickup_quantity_with_stock_and_debt_consistency(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'P-EDIT', 'name' => 'Kho']);
        $product = DB::table('products')->insertGetId(['code' => 'G-EDIT', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 1000, 'direct_purchase_allowed' => true]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 3]);
        $orderId = app(CommerceWorkflow::class)->placePickupOrder($customer->id, [$product => 1], $warehouse);
        $itemId = DB::table('order_items')->where('order_id', $orderId)->value('id');

        $this->actingAs($customer)->put('/my/orders/'.$orderId, ['quantities' => [$itemId => 4]])->assertSessionHasErrors('workflow');
        $this->actingAs($customer)->put('/my/orders/'.$orderId, ['quantities' => [$itemId => 2]])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'total' => 2000]);
        $this->assertDatabaseHas('debts', ['order_id' => $orderId, 'amount' => 2000]);
        $this->assertDatabaseHas('order_items', ['id' => $itemId, 'quantity' => 2]);
        $this->get('/my/orders')->assertOk()->assertSee('value="2"', false)->assertDontSee('value="2.000"', false);
        DB::table('orders')->where('id', $orderId)->update(['paid' => 1000]);
        $this->put('/my/orders/'.$orderId, ['quantities' => [$itemId => 1]])->assertSessionHasErrors('workflow');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'total' => 2000]);
    }

    public function test_shortage_is_shown_to_staff_on_requested_quote(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $product = DB::table('products')->insertGetId(['code' => 'XM-THIEU', 'name' => 'Xi măng thiếu', 'unit' => 'bao', 'price' => 100]);
        $quote = app(CommerceWorkflow::class)->requestQuote($customer->id, [$product => 5], 'Công trình A');

        $this->assertDatabaseHas('quotes', ['id' => $quote, 'status' => 'requested']);
        $this->actingAs($sales)->get('/staff/work/quotes')->assertOk()->assertSee('cần nhập thêm');
    }

    public function test_accounting_can_pay_supplier_debt_but_cannot_overpay(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $supplier = DB::table('suppliers')->insertGetId(['code' => 'NCC-TT', 'name' => 'Nhà cung cấp']);
        $debt = DB::table('debts')->insertGetId([
            'code' => 'CN-NCC-TT', 'direction' => 'payable', 'supplier_id' => $supplier,
            'amount' => 100, 'paid' => 0, 'status' => 'open',
        ]);

        $url = '/staff/debts/'.$debt.'/payments';
        $this->actingAs($sales)->post($url, ['amount' => 40, 'method' => 'bank_transfer'])->assertForbidden();
        $this->actingAs($accountant)->post($url, ['amount' => 40, 'method' => 'bank_transfer', 'reference' => 'BANK-001'])->assertRedirect();
        $this->assertDatabaseHas('debts', ['id' => $debt, 'paid' => 40, 'status' => 'open']);
        $this->assertDatabaseHas('payments', ['debt_id' => $debt, 'amount' => 40, 'reference' => 'BANK-001']);
        $this->post($url, ['amount' => 61, 'method' => 'cash'])->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('payments', 1);
        $this->post($url, ['amount' => 60, 'method' => 'cash'])->assertRedirect();
        $this->assertDatabaseHas('debts', ['id' => $debt, 'paid' => 100, 'status' => 'settled']);
    }

    public function test_exchange_waits_for_manual_restock_when_replacement_is_unavailable(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $staff = User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'K-DOI', 'name' => 'Kho đổi hàng']);
        $product = DB::table('products')->insertGetId(['code' => 'G-DOI', 'name' => 'Gạch đổi', 'unit' => 'viên', 'price' => 100]);
        $commerce = app(CommerceWorkflow::class);
        $fulfillment = app(FulfillmentWorkflow::class);
        $quote = $commerce->requestQuote($customer->id, [$product => 1], 'Công trình A');
        $commerce->sendQuote($quote, $staff->id, [], now()->addWeek()->toDateString());
        $commerce->acceptQuote($quote, $customer->id);
        $contract = $commerce->createContract($quote, $staff->id, '0', 'Thanh toán sau', 'Giao công trình');
        $commerce->submitContract($contract);
        $commerce->reviewContract($contract, $staff->id, true);
        $commerce->respondToContract($contract, $customer->id, true);
        $order = $commerce->signContract($contract, 'contracts/signed.pdf');
        DB::table('orders')->where('id', $order)->update(['status' => 'delivered']);
        $return = $fulfillment->requestReturn($order, $customer->id, $product, '1', 'Một viên vỡ', 'exchange');
        $fulfillment->inspectReturn($return, $warehouse, $staff->id, false, 'Hư hỏng');

        try {
            $fulfillment->issueExchange($return, $warehouse, $staff->id, 'Giao nhận', 'Xe máy', 'Công trình A');
            $this->fail('Không được lập chuyến giao đổi khi kho hết hàng.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('returns', ['id' => $return, 'status' => 'inspected']);
            $this->assertDatabaseCount('shipments', 0);
            $this->assertDatabaseCount('stock_documents', 0);
        }

        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 1]);
        $replacement = $fulfillment->issueExchange($return, $warehouse, $staff->id, 'Giao nhận', 'Xe máy', 'Công trình A');
        $this->assertDatabaseHas('shipments', ['id' => $replacement, 'return_id' => $return, 'status' => 'planned']);
        $this->assertDatabaseHas('returns', ['id' => $return, 'status' => 'replacement_shipping']);
        $this->assertDatabaseHas('inventory', ['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 0]);
    }

    public function test_demo_opening_stock_is_audited_and_seeded_only_once(): void
    {
        User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        DB::table('warehouses')->insert(['code' => 'D1', 'name' => 'Kho demo']);
        DB::table('products')->insert(['code' => 'D-XM', 'name' => 'Xi măng demo', 'unit' => 'bao', 'price' => 92000, 'direct_purchase_allowed' => true]);
        $seeder = new DemoOpeningStockSeeder;
        $seeder->run();
        $seeder->run();
        $this->assertDatabaseCount('stock_documents', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('inventory', ['quantity' => 100]);
    }

    public function test_reference_price_product_requires_a_quote(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'R1', 'name' => 'Kho']);
        $product = DB::table('products')->insertGetId(['code' => 'THEP-KG', 'name' => 'Thép theo kg', 'unit' => 'kg', 'price' => 15500]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 100]);
        $this->actingAs($customer)->withSession(['cart' => [$product => 2]])
            ->get('/cart')->assertOk()->assertSee('Giỏ có vật liệu cần nhân viên xác nhận giá.');
        $this->post('/pickup-orders', ['warehouse_id' => $warehouse])->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('orders', 0);
        $this->post('/quotes', ['delivery_address' => 'Công trình A'])->assertRedirect('/my/quotes');
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_pickup_order_requires_stock_and_full_payment_before_handover(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $warehouseStaff = User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'P1', 'name' => 'Kho nhận hàng', 'address' => 'Bình Chánh']);
        $product = DB::table('products')->insertGetId(['code' => 'XM-P', 'name' => 'Xi măng', 'unit' => 'bao', 'price' => 92000, 'direct_purchase_allowed' => true]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 3]);

        $this->actingAs($customer)->withSession(['cart' => [$product => 2]])
            ->get('/cart')->assertOk()->assertSee('Đặt hàng nhận tại kho')->assertSee('Yêu cầu báo giá công trình');
        $this->post('/pickup-orders', ['warehouse_id' => $warehouse])->assertRedirect('/my/orders');
        $order = DB::table('orders')->first();
        $this->assertSame('pickup', $order->fulfillment_method);
        $this->assertNull($order->contract_id);
        $this->assertSame('awaiting_confirmation', $order->status);
        $this->assertEquals(184000, $order->total);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $product, 'quantity' => 2]);
        $this->withSession(['cart' => [$product => 2]])->post('/pickup-orders', ['warehouse_id' => $warehouse])->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('orders', 1);

        $debt = DB::table('debts')->where('order_id', $order->id)->first();
        $commerce = app(CommerceWorkflow::class);
        $fulfillment = app(FulfillmentWorkflow::class);
        $commerce->reviewPickupOrder($order->id, $sales->id, true);
        $commerce->recordPayment($debt->id, $accountant->id, '92000', 'cash');
        $this->assertSame('awaiting_payment', DB::table('orders')->find($order->id)->status);
        $commerce->recordPayment($debt->id, $accountant->id, '92000', 'cash');
        $this->assertSame('ready_to_issue', DB::table('orders')->find($order->id)->status);
        $commerce->issueInvoice($order->id, $accountant->id);
        $fulfillment->issueOrder($order->id, $warehouse, $warehouseStaff->id);
        $this->assertEquals(1, DB::table('inventory')->first()->quantity);
        $this->actingAs($warehouseStaff)->post('/staff/orders/'.$order->id.'/pickup', ['recipient' => 'Nguyễn Văn A'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'delivered', 'pickup_recipient' => 'Nguyễn Văn A']);
    }

    public function test_customer_can_cancel_unpaid_pickup_order_to_release_stock(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'P2', 'name' => 'Kho']);
        $product = DB::table('products')->insertGetId(['code' => 'G-P', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 1000, 'direct_purchase_allowed' => true]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 2]);
        $commerce = app(CommerceWorkflow::class);
        $order = $commerce->placePickupOrder($customer->id, [$product => 2], $warehouse);
        $other = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $this->actingAs($other)->post('/my/orders/'.$order.'/cancel')->assertSessionHasErrors('workflow');
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'awaiting_confirmation']);
        $this->actingAs($customer)->post('/my/orders/'.$order.'/cancel')->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'cancelled']);
        $this->assertDatabaseHas('debts', ['order_id' => $order, 'amount' => 0, 'status' => 'settled']);
        $commerce->placePickupOrder($customer->id, [$product => 2], $warehouse);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_quote_deposit_stock_delivery_and_return(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        DB::table('customers')->insert(['user_id' => $customer->id, 'code' => 'KH-1', 'credit_limit' => 0]);
        $staff = User::factory()->create();
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'K1', 'name' => 'Kho chính']);
        $product = DB::table('products')->insertGetId(['code' => 'XM1', 'name' => 'Xi măng', 'unit' => 'bao', 'price' => 100]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 5]);
        $commerce = app(CommerceWorkflow::class);
        $fulfillment = app(FulfillmentWorkflow::class);
        $quote = $commerce->requestQuote($customer->id, [$product => 2], 'Công trình A');
        $commerce->sendQuote($quote, $staff->id, [], now()->addWeek()->toDateString());
        $commerce->acceptQuote($quote, $customer->id);
        $contract = $commerce->createContract($quote, $staff->id, '100', 'Cọc 100', 'Giao tại công trình');
        $commerce->submitContract($contract);
        $commerce->reviewContract($contract, $staff->id, true);
        $commerce->respondToContract($contract, $customer->id, true);
        $order = $commerce->signContract($contract, 'contracts/signed.pdf');
        $this->assertSame('awaiting_payment', DB::table('orders')->find($order)->status);
        try {
            $commerce->releaseOrder($order, $staff->id);
            $fulfillment->issueOrder($order, $warehouse, $staff->id);
            $this->fail('Không được xuất kho trước khi nhận cọc');
        } catch (ValidationException) {
            $this->assertEquals(5, DB::table('inventory')->first()->quantity);
        }
        $debt = DB::table('debts')->where('order_id', $order)->first();
        $commerce->recordPayment($debt->id, $staff->id, '100', 'cash');
        $commerce->issueInvoice($order, $staff->id);
        $this->assertDatabaseHas('orders', ['id' => $order, 'released_by' => $staff->id]);
        $fulfillment->issueOrder($order, $warehouse, $staff->id);
        $this->assertEquals(3, DB::table('inventory')->first()->quantity);
        $shipment = $fulfillment->planShipment($order, null, 'Đơn vị vận chuyển', 'Xe tải', 'Công trình A');
        try {
            $fulfillment->handover($shipment, 'Khách', true, 'Đủ hàng');
            $this->fail('Chưa được bàn giao trước khi đơn vị vận chuyển nhận hàng');
        } catch (ValidationException) {
            $this->assertSame('planned', DB::table('shipments')->find($shipment)->status);
        }
        $fulfillment->pickupShipment($shipment, $staff->id, 'Tài xế A');
        $this->assertSame('shipping', DB::table('shipments')->find($shipment)->status);
        $fulfillment->handover($shipment, 'Khách', false, 'Thiếu hàng tại điểm giao');
        $this->assertSame('delivery_incident', DB::table('orders')->find($order)->status);
        $fulfillment->rescheduleShipment($shipment, 'Xe tải khác', 'Giao lại sáng mai');
        $fulfillment->pickupShipment($shipment, $staff->id, 'Tài xế B');
        $fulfillment->handover($shipment, 'Khách', true, 'Đủ hàng');
        $commerce->recordPayment($debt->id, $staff->id, '100', 'cash');
        $return = $fulfillment->requestReturn($order, $customer->id, $product, '1', 'Một bao lỗi');
        $fulfillment->inspectReturn($return, $warehouse, $staff->id, true, 'Sử dụng được');
        $fulfillment->settleReturn($return, $staff->id);
        $this->assertEquals(4, DB::table('inventory')->first()->quantity);
        $this->assertEquals(100, DB::table('debts')->find($debt->id)->amount);
        $this->assertSame('completed', DB::table('returns')->find($return)->status);
        $credit = DB::table('customer_credits')->where('return_id', $return)->first();
        $this->assertEquals(100, $credit->amount);

        $nextQuote = $commerce->requestQuote($customer->id, [$product => 1], 'Công trình B');
        $commerce->sendQuote($nextQuote, $staff->id, [], now()->addWeek()->toDateString());
        $commerce->acceptQuote($nextQuote, $customer->id);
        $nextContract = $commerce->createContract($nextQuote, $staff->id, '50', 'Cọc 50', 'Giao tại công trình');
        $commerce->submitContract($nextContract);
        $commerce->reviewContract($nextContract, $staff->id, true);
        $commerce->respondToContract($nextContract, $customer->id, true);
        $nextOrder = $commerce->signContract($nextContract, 'contracts/second.pdf');
        $nextDebt = DB::table('debts')->where('order_id', $nextOrder)->first();
        $commerce->useCustomerCredit($credit->id, $staff->id, '60', 'offset', $nextDebt->id);
        $this->assertEquals(60, DB::table('debts')->find($nextDebt->id)->paid);
        $this->assertSame('ready_to_issue', DB::table('orders')->find($nextOrder)->status);
        $commerce->useCustomerCredit($credit->id, $staff->id, '40', 'refund', null, 'bank_transfer', 'REF-1');
        $this->assertDatabaseHas('customer_credits', ['id' => $credit->id, 'status' => 'settled']);
        $this->assertDatabaseCount('customer_credit_movements', 2);
        $this->actingAs($customer)->get('/my/profile')->assertOk()->assertSee('Tiền được hoàn hoặc cấn trừ');
        $this->expectException(ValidationException::class);
        $commerce->useCustomerCredit($credit->id, $staff->id, '1', 'refund', null, 'cash');
    }

    public function test_exchange_issues_replacement_without_reducing_customer_debt(): void
    {
        $customer = User::factory()->create();
        DB::table('customers')->insert(['user_id' => $customer->id, 'code' => 'KH-2', 'credit_limit' => 500]);
        $staff = User::factory()->create();
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'K2', 'name' => 'Kho']);
        $supplier = DB::table('suppliers')->insertGetId(['code' => 'N2', 'name' => 'Nhà cung cấp gạch']);
        $otherSupplier = DB::table('suppliers')->insertGetId(['code' => 'N3', 'name' => 'Nhà cung cấp khác']);
        $product = DB::table('products')->insertGetId(['code' => 'G2', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 10]);
        $purchase = DB::table('purchase_orders')->insertGetId([
            'code' => 'MH-N2', 'supplier_id' => $supplier, 'warehouse_id' => $warehouse,
            'requested_by' => $staff->id, 'status' => 'received',
        ]);
        DB::table('purchase_items')->insert([
            'purchase_order_id' => $purchase, 'product_id' => $product,
            'quantity' => 10, 'unit_price' => 5, 'received_quantity' => 10,
        ]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 10]);
        $commerce = app(CommerceWorkflow::class);
        $fulfillment = app(FulfillmentWorkflow::class);
        $quote = $commerce->requestQuote($customer->id, [$product => 2], 'Công trình B');
        $commerce->sendQuote($quote, $staff->id, [], now()->addWeek()->toDateString());
        $commerce->acceptQuote($quote, $customer->id);
        $contract = $commerce->createContract($quote, $staff->id, '0', 'Thanh toán sau', 'Giao công trình');
        $commerce->submitContract($contract);
        $commerce->reviewContract($contract, $staff->id, true);
        $commerce->respondToContract($contract, $customer->id, true);
        $order = $commerce->signContract($contract, 'contracts/signed.pdf');
        $commerce->issueInvoice($order, $staff->id);
        $fulfillment->issueOrder($order, $warehouse, $staff->id);
        $shipment = $fulfillment->planShipment($order, null, 'Đơn vị vận chuyển', 'Xe tải', 'Công trình B');
        $fulfillment->pickupShipment($shipment, $staff->id, 'Tài xế B');
        $fulfillment->handover($shipment, 'Khách', true, 'Đủ hàng');
        $return = $fulfillment->requestReturn($order, $customer->id, $product, '1', 'Một viên vỡ', 'exchange');
        $fulfillment->inspectReturn($return, $warehouse, $staff->id, false, 'Hư hỏng');
        try {
            $fulfillment->sendDamagedReturnToSupplier($return, $otherSupplier, $staff->id, 'Sai nhà cung cấp');
            $this->fail('Không được trả cho nhà cung cấp chưa giao vật liệu');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('supplier_returns', ['return_id' => $return]);
        }
        $fulfillment->sendDamagedReturnToSupplier($return, $supplier, $staff->id, 'Giao nhà cung cấp xử lý gạch vỡ');
        $this->assertDatabaseHas('supplier_returns', ['return_id' => $return, 'supplier_id' => $supplier]);
        $this->assertEquals(8, DB::table('inventory')->first()->quantity);
        $payable = DB::table('debts')->insertGetId([
            'code' => 'CN-NCC-1', 'direction' => 'payable', 'supplier_id' => $supplier,
            'amount' => 10, 'paid' => 0, 'status' => 'open',
        ]);
        $supplierReturn = DB::table('supplier_returns')->where('return_id', $return)->first();
        app(CommerceWorkflow::class)->settleSupplierReturn($supplierReturn->id, $payable, $staff->id, '5', 'Nhà cung cấp xác nhận giảm công nợ');
        $this->assertEquals(5, DB::table('debts')->find($payable)->amount);
        try {
            app(CommerceWorkflow::class)->settleSupplierReturn($supplierReturn->id, $payable, $staff->id, '5', 'Lặp bù trừ');
            $this->fail('Không được bù trừ hai lần');
        } catch (ValidationException) {
            $this->assertEquals(5, DB::table('debts')->find($payable)->amount);
        }
        try {
            $fulfillment->sendDamagedReturnToSupplier($return, $supplier, $staff->id, 'Gửi lại');
            $this->fail('Không được trả nhà cung cấp hai lần');
        } catch (ValidationException) {
            $this->assertDatabaseCount('supplier_returns', 1);
        }
        $replacement = $fulfillment->issueExchange($return, $warehouse, $staff->id, 'Giao nhận', 'Xe máy', 'Công trình B');
        $fulfillment->pickupShipment($replacement, $staff->id, 'Tài xế C');
        $fulfillment->handover($replacement, 'Khách', true, 'Đã nhận hàng thay thế');
        $this->assertEquals(7, DB::table('inventory')->first()->quantity);
        $this->assertEquals(20, DB::table('debts')->where('order_id', $order)->first()->amount);
        $this->assertSame('completed', DB::table('returns')->find($return)->status);
    }

    public function test_discount_quote_waits_for_management_before_customer_can_accept(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->create();
        $product = DB::table('products')->insertGetId(['code' => 'XM', 'name' => 'Xi măng', 'unit' => 'bao', 'price' => 100]);
        $workflow = app(CommerceWorkflow::class);
        $quote = $workflow->requestQuote($customer->id, [$product => 2], 'Công trình A');
        $line = DB::table('quote_items')->where('quote_id', $quote)->first();
        $workflow->sendQuote($quote, $staff->id, [$line->id => 90], now()->addWeek()->toDateString());
        $this->assertSame('pending_discount_approval', DB::table('quotes')->find($quote)->status);
        try {
            $workflow->acceptQuote($quote, $customer->id);
            $this->fail('Khách không được chấp nhận giá chưa duyệt');
        } catch (ValidationException) {
            $this->assertSame('pending_discount_approval', DB::table('quotes')->find($quote)->status);
        }
        $workflow->reviewQuoteDiscount($quote, $staff->id, true);
        $workflow->acceptQuote($quote, $customer->id);
        $this->assertSame('accepted', DB::table('quotes')->find($quote)->status);
    }

    public function test_contract_requires_its_customer_confirmation_and_invoice_before_release(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $staff = User::factory()->create();
        $product = DB::table('products')->insertGetId(['code' => 'G-QA', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        $workflow = app(CommerceWorkflow::class);
        $quote = $workflow->requestQuote($customer->id, [$product => 2], 'Công trình A');
        $workflow->sendQuote($quote, $staff->id, [], now()->addWeek()->toDateString());
        $workflow->acceptQuote($quote, $customer->id);
        $contract = $workflow->createContract($quote, $staff->id, '0', 'Thanh toán sau', 'Giao tại công trình');
        $workflow->submitContract($contract);
        $workflow->reviewContract($contract, $staff->id, true);

        foreach ([fn () => $workflow->respondToContract($contract, $other->id, true),
            fn () => $workflow->signContract($contract, 'contracts/signed.pdf')] as $action) {
            try {
                $action();
                $this->fail('Không được bỏ qua xác nhận của đúng khách hàng.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('orders', ['contract_id' => $contract]);
            }
        }
        $workflow->respondToContract($contract, $customer->id, false, 'Cần sửa thời gian giao.');
        $this->assertDatabaseHas('contracts', ['id' => $contract, 'status' => 'changes_requested']);
        $workflow->submitContract($contract);
        $workflow->reviewContract($contract, $staff->id, true);
        $workflow->respondToContract($contract, $customer->id, true);
        $order = $workflow->signContract($contract, 'contracts/signed.pdf');
        try {
            $workflow->cancelContract($contract, $staff->id, 'Hủy sau ký');
            $this->fail('Không được hủy hợp đồng đã ký');
        } catch (ValidationException) {
            $this->assertDatabaseHas('contracts', ['id' => $contract, 'status' => 'signed']);
        }
        try {
            $workflow->releaseOrder($order, $staff->id);
            $this->fail('Không được xuất trước khi có hóa đơn.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('invoices', 0);
        }
        $workflow->issueInvoice($order, $staff->id);
        $this->assertDatabaseHas('invoices', ['order_id' => $order, 'total' => 200]);
        $this->assertDatabaseHas('orders', ['id' => $order, 'released_by' => $staff->id]);
    }

    public function test_purchase_receipt_changes_stock_only_after_approval(): void
    {
        $staff = User::factory()->create();
        $supplier = DB::table('suppliers')->insertGetId(['code' => 'N1', 'name' => 'Nhà cung cấp']);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'K1', 'name' => 'Kho chính']);
        $product = DB::table('products')->insertGetId(['code' => 'G1', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 2]);
        $service = app(FulfillmentWorkflow::class);
        $purchase = $service->createPurchaseOrder($supplier, $warehouse, $staff->id, [['product_id' => $product, 'quantity' => 10, 'unit_price' => 1]]);
        $this->assertDatabaseCount('inventory', 0);
        $service->approvePurchaseOrder($purchase, $staff->id);
        $line = DB::table('purchase_items')->where('purchase_order_id', $purchase)->first();
        $service->receivePurchaseOrder($purchase, $staff->id, [$line->id => 7]);
        $this->assertEquals(7, DB::table('inventory')->first()->quantity);
        $this->assertEquals(7, DB::table('debts')->where('direction', 'payable')->first()->amount);
        $payable = DB::table('debts')->where('direction', 'payable')->first();
        $dueDate = now()->addWeek()->toDateString();
        app(CommerceWorkflow::class)->scheduleDebt($payable->id, $dueDate, 'Nhà cung cấp xác nhận ngày thanh toán');
        $this->assertDatabaseHas('debts', ['id' => $payable->id, 'due_on' => $dueDate]);
        $incident = $service->reportSupplierIncident($purchase, $line->id, $staff->id, 'damaged', '2', 'Hai viên vỡ');
        $this->assertDatabaseHas('supplier_incidents', ['id' => $incident, 'status' => 'open']);
        $this->assertEquals(7, DB::table('inventory')->first()->quantity);
        try {
            $service->reportSupplierIncident($purchase, $line->id, $staff->id, 'shortage', '2', 'Giao thiếu');
            $this->fail('Không được ghi sự vụ vượt số lượng chưa nhập');
        } catch (ValidationException) {
            $this->assertDatabaseCount('supplier_incidents', 1);
        }
        $service->resolveSupplierIncident($incident, $staff->id, 'Đã trả hàng hỏng cho nhà cung cấp');
        $this->assertDatabaseHas('supplier_incidents', ['id' => $incident, 'status' => 'resolved']);
    }

    public function test_two_planned_shipments_can_share_one_route(): void
    {
        $staff = User::factory()->create();
        $orders = [];
        foreach ([1, 2] as $number) {
            $quote = DB::table('quotes')->insertGetId([
                'code' => 'BG-'.$number, 'customer_id' => $staff->id,
                'delivery_address' => 'Công trình '.$number,
            ]);
            $contract = DB::table('contracts')->insertGetId([
                'code' => 'HD-'.$number, 'quote_id' => $quote, 'created_by' => $staff->id, 'total' => 10,
            ]);
            $order = DB::table('orders')->insertGetId([
                'code' => 'DH-'.$number, 'contract_id' => $contract, 'customer_id' => $staff->id,
                'total' => 10, 'delivery_address' => 'Công trình '.$number,
            ]);
            $orders[] = DB::table('shipments')->insertGetId([
                'code' => 'GN-'.$number, 'order_id' => $order, 'status' => 'planned',
            ]);
        }
        $service = app(FulfillmentWorkflow::class);
        $run = $service->groupDeliveryRun($orders, $staff->id, 'Vận tải A', 'Xe tải', 'Hai công trình cùng tuyến');
        $this->assertSame(2, DB::table('shipments')->where('delivery_run_id', $run)->count());
        $this->assertDatabaseHas('delivery_runs', ['id' => $run, 'vehicle' => 'Xe tải']);
        try {
            $service->groupDeliveryRun($orders, $staff->id, 'Vận tải A', 'Xe tải', 'Tuyến trùng');
            $this->fail('Không được ghép một chuyến vào hai lộ trình');
        } catch (ValidationException) {
            $this->assertDatabaseCount('delivery_runs', 1);
        }
    }

    public function test_management_can_cancel_only_unsigned_contract(): void
    {
        $customer = User::factory()->create();
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $product = DB::table('products')->insertGetId(['code' => 'XM-C', 'name' => 'Xi măng', 'unit' => 'bao', 'price' => 100]);
        $workflow = app(CommerceWorkflow::class);
        $quote = $workflow->requestQuote($customer->id, [$product => 1], 'Công trình A');
        $workflow->sendQuote($quote, $manager->id, [], now()->addWeek()->toDateString());
        $workflow->acceptQuote($quote, $customer->id);
        $contract = $workflow->createContract($quote, $manager->id, '0', 'Sau giao hàng', 'Tại công trình');
        $code = DB::table('contracts')->find($contract)->code;
        $this->actingAs($manager)->get('/staff/work/contracts?contract='.$code)->assertOk()->assertSee($code);
        $this->actingAs($manager)->get('/staff/work/contracts?contract=khong-co')->assertOk()->assertDontSee($code);
        $workflow->cancelContract($contract, $manager->id, 'Khách thay đổi kế hoạch');
        $this->assertDatabaseHas('contracts', ['id' => $contract, 'status' => 'cancelled', 'cancelled_by' => $manager->id]);
        try {
            $workflow->submitContract($contract);
            $this->fail('Hợp đồng đã hủy không thể trình duyệt');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('orders', ['contract_id' => $contract]);
        }
    }
}
