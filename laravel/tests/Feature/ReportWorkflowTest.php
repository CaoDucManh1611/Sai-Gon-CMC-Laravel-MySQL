<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
        $this->actingAs($accountant)->get('/staff/reports/'.$report->id.'/export')->assertOk()->assertDownload($report->code.'.csv');
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
