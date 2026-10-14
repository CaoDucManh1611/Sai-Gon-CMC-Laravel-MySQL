<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_report_filters_orders_by_category_customer_and_reviewing_staff(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $otherCustomer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $category = DB::table('categories')->insertGetId(['code' => 'CAT-R', 'name' => 'Gạch']);
        $product = DB::table('products')->insertGetId(['code' => 'G-R', 'name' => 'Gạch lọc', 'category_id' => $category, 'unit' => 'viên']);
        $otherProduct = DB::table('products')->insertGetId(['code' => 'X-R', 'name' => 'Xi măng lọc', 'unit' => 'bao']);
        foreach ([[$customer->id, $product, $sales->id], [$otherCustomer->id, $otherProduct, $sales->id]] as $index => [$customerId, $productId, $staffId]) {
            $order = DB::table('orders')->insertGetId([
                'code' => 'DH-R-'.$index, 'customer_id' => $customerId,
                'reviewed_by' => $staffId, 'delivery_address' => 'Công trình', 'total' => 100,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $productId, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        }

        $this->actingAs($manager)->post('/staff/reports', [
            'type' => 'sales', 'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(),
            'category_id' => $category, 'customer_id' => $customer->id, 'staff_id' => $sales->id,
        ])->assertRedirect('/staff/reports');
        $report = DB::table('reports')->first();
        $snapshot = json_decode($report->snapshot, true);
        $this->assertCount(1, $snapshot['orders']);
        $this->assertSame('DH-R-0', $snapshot['orders'][0]['code']);
        $this->assertSame($category, json_decode($report->filters, true)['category_id']);
        $this->actingAs($manager)->post('/staff/reports/'.$report->id.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->get('/staff/reports/'.$report->id.'/print')->assertOk()->assertSee('Biểu đồ Đơn hàng');
    }

    public function test_inventory_report_filters_products_by_supplier_purchase_history(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'K-RPT', 'name' => 'Kho']);
        $supplier = DB::table('suppliers')->insertGetId(['code' => 'N-RPT', 'name' => 'Nhà cung cấp A']);
        $wanted = DB::table('products')->insertGetId(['code' => 'P-RPT-A', 'name' => 'Vật liệu A', 'unit' => 'viên']);
        $other = DB::table('products')->insertGetId(['code' => 'P-RPT-B', 'name' => 'Vật liệu B', 'unit' => 'viên']);
        DB::table('inventory')->insert([
            ['warehouse_id' => $warehouse, 'product_id' => $wanted, 'quantity' => 8, 'created_at' => now(), 'updated_at' => now()],
            ['warehouse_id' => $warehouse, 'product_id' => $other, 'quantity' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $purchase = DB::table('purchase_orders')->insertGetId([
            'code' => 'MH-RPT', 'supplier_id' => $supplier, 'warehouse_id' => $warehouse,
            'requested_by' => $manager->id, 'status' => 'received',
        ]);
        DB::table('purchase_items')->insert(['purchase_order_id' => $purchase, 'product_id' => $wanted,
            'quantity' => 8, 'received_quantity' => 8, 'unit_price' => 1]);

        $this->actingAs($manager)->post('/staff/reports', [
            'type' => 'inventory', 'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(),
            'supplier_id' => $supplier,
        ])->assertRedirect('/staff/reports');
        $snapshot = json_decode(DB::table('reports')->first()->snapshot, true);
        $this->assertCount(1, $snapshot['stock']);
        $this->assertSame('P-RPT-A', $snapshot['stock'][0]['product']);
    }

    public function test_report_requires_approval_and_respects_department_scope(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $this->actingAs($accountant)->post('/staff/reports', [
            'type' => 'sales', 'from_date' => '2026-01-01', 'to_date' => '2026-01-31',
        ])->assertForbidden();
        $this->actingAs($accountant)->post('/staff/reports', [
            'type' => 'finance', 'from_date' => '2026-01-01', 'to_date' => '2026-01-31',
        ])->assertRedirect('/staff/reports');
        foreach (['inventory', 'sales', 'logistics'] as $type) {
            $this->actingAs($manager)->post('/staff/reports', [
                'type' => $type, 'from_date' => '2026-01-01', 'to_date' => '2026-01-31',
            ])->assertRedirect('/staff/reports');
        }
        $this->assertDatabaseCount('reports', 4);
        $report = DB::table('reports')->where('type', 'finance')->first();
        $this->actingAs($accountant)->get('/staff/reports/'.$report->id.'/export')->assertNotFound();
        $this->actingAs($manager)->post('/staff/reports/'.$report->id.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($sales)->get('/staff/reports/'.$report->id.'/export')->assertForbidden();
        $this->actingAs($sales)->get('/staff/reports/'.$report->id.'/print')->assertForbidden();
        $this->actingAs($accountant)->get('/staff/reports/'.$report->id.'/print')->assertOk()->assertSee($report->code);
        $export = $this->actingAs($accountant)->get('/staff/reports/'.$report->id.'/export')
            ->assertOk()->assertDownload($report->code.'.xlsx');
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $export->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $export->streamedContent());
        $pdf = $this->actingAs($accountant)->get('/staff/reports/'.$report->id.'/pdf')
            ->assertOk()->assertDownload($report->code.'.pdf');
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());
    }

    public function test_past_reports_exclude_later_payments_and_stock_changes(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $debt = DB::table('debts')->insertGetId([
            'code' => 'CN-PAST', 'direction' => 'receivable', 'customer_id' => $accountant->id,
            'amount' => 100, 'paid' => 50, 'status' => 'open',
            'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-02-01 12:00:00',
        ]);
        foreach ([['PAY-OLD', 20, '2026-01-15 12:00:00'], ['PAY-NEW', 30, '2026-02-01 12:00:00']] as [$code, $amount, $date]) {
            DB::table('payments')->insert([
                'code' => $code, 'debt_id' => $debt, 'recorded_by' => $accountant->id,
                'amount' => $amount, 'method' => 'cash', 'paid_at' => $date,
                'created_at' => $date, 'updated_at' => $date,
            ]);
        }
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'WH-PAST', 'name' => 'Kho báo cáo']);
        $product = DB::table('products')->insertGetId([
            'code' => 'PR-PAST', 'name' => 'Vật liệu báo cáo', 'unit' => 'bao', 'price' => 100,
        ]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 7]);
        $document = DB::table('stock_documents')->insertGetId([
            'code' => 'PN-PAST', 'type' => 'receipt', 'warehouse_id' => $warehouse,
            'created_by' => $accountant->id, 'status' => 'approved',
        ]);
        DB::table('stock_movements')->insert([
            'stock_document_id' => $document, 'warehouse_id' => $warehouse, 'product_id' => $product,
            'change' => 2, 'balance_after' => 7,
            'created_at' => '2026-02-01 12:00:00', 'updated_at' => '2026-02-01 12:00:00',
        ]);

        foreach ([[$accountant, 'finance'], [$manager, 'inventory']] as [$user, $type]) {
            $this->actingAs($user)->post('/staff/reports', [
                'type' => $type, 'from_date' => '2026-01-01', 'to_date' => '2026-01-31',
            ])->assertRedirect('/staff/reports');
        }
        $finance = json_decode(DB::table('reports')->where('type', 'finance')->value('snapshot'), true);
        $inventory = json_decode(DB::table('reports')->where('type', 'inventory')->value('snapshot'), true);
        $this->assertEquals(20, $finance['payments'][0]['amount']);
        $this->assertEquals(100, $finance['debts'][0]['amount']);
        $this->assertEquals(20, $finance['debts'][0]['paid']);
        $this->assertEquals(5, $inventory['stock'][0]['quantity']);
        $financeReport = DB::table('reports')->where('type', 'finance')->first();
        $this->actingAs($manager)->post('/staff/reports/'.$financeReport->id.'/review', ['decision' => 'approved'])->assertRedirect();
        $excel = $this->actingAs($accountant)->get('/staff/reports/'.$financeReport->id.'/export')->assertOk()->streamedContent();
        $this->assertStringStartsWith('PK', $excel);
    }

    public function test_finance_snapshot_does_not_count_unsigned_order_as_receivable(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $order = DB::table('orders')->insertGetId([
            'code' => 'DH-DRAFT', 'customer_id' => $customer->id, 'status' => 'awaiting_confirmation',
            'total' => 100, 'delivery_address' => 'Công trình A', 'requires_contract' => true,
            'created_at' => '2026-01-05 12:00:00', 'updated_at' => '2026-01-05 12:00:00',
        ]);
        DB::table('debts')->insert([
            'code' => 'CN-DRAFT', 'direction' => 'receivable', 'customer_id' => $customer->id,
            'order_id' => $order, 'amount' => 100, 'status' => 'open',
            'created_at' => '2026-01-05 12:00:00', 'updated_at' => '2026-01-05 12:00:00',
        ]);

        $this->actingAs($accountant)->post('/staff/reports', [
            'type' => 'finance', 'from_date' => '2026-01-01', 'to_date' => '2026-01-31',
        ])->assertRedirect('/staff/reports');
        $finance = json_decode(DB::table('reports')->where('type', 'finance')->value('snapshot'), true);
        $this->assertSame([], $finance['debts']);
    }

    public function test_finance_report_caps_collected_amount_after_return_credit(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $debt = DB::table('debts')->insertGetId([
            'code' => 'CN-RETURN', 'direction' => 'receivable', 'customer_id' => $accountant->id,
            'amount' => 100, 'paid' => 150, 'status' => 'settled',
            'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-10 12:00:00',
        ]);
        DB::table('payments')->insert([
            'code' => 'PAY-RETURN', 'debt_id' => $debt, 'recorded_by' => $accountant->id,
            'amount' => 150, 'method' => 'cash', 'paid_at' => '2026-01-05 12:00:00',
            'created_at' => '2026-01-05 12:00:00', 'updated_at' => '2026-01-05 12:00:00',
        ]);

        $this->actingAs($accountant)->post('/staff/reports', [
            'type' => 'finance', 'from_date' => '2026-01-01', 'to_date' => '2026-01-31',
        ])->assertRedirect('/staff/reports');
        $finance = json_decode(DB::table('reports')->where('type', 'finance')->value('snapshot'), true);
        $this->assertEquals(100, $finance['debts'][0]['amount']);
        $this->assertEquals(100, $finance['debts'][0]['paid']);
    }
}
