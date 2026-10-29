<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DebtReconciliationWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DebtReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_calculates_period_totals_and_stays_unchanged_after_a_later_payment(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $asOfDate = CarbonImmutable::now()->subDay();
        $periodStart = $asOfDate->startOfMonth();
        $openingDebt = $this->debt($customer->id, 'NO-OPEN', '1000.00', $periodStart->subDays(3));
        $currentDebt = $this->debt($customer->id, 'NO-CURRENT', '500.00', $periodStart->addDays(2));
        $this->payment($currentDebt, $accountant->id, '100.00', $asOfDate->subDays(2));
        DB::table('debts')->where('id', $currentDebt)->update(['paid' => '100.00']);

        $workflow = app(DebtReconciliationWorkflow::class);
        $reconciliationId = $workflow->createSnapshot($customer->id, $asOfDate->toDateString(), $accountant->id);
        $reconciliation = DB::table('debt_reconciliations')->find($reconciliationId);
        $snapshotBeforeLaterPayment = $reconciliation->content_snapshot;
        $snapshot = json_decode($snapshotBeforeLaterPayment, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('draft', $reconciliation->status);
        $this->assertSame($periodStart->toDateString(), $reconciliation->period_start);
        $this->assertSame($asOfDate->toDateString(), $reconciliation->as_of_date);
        $this->assertSame(0, bccomp((string) $reconciliation->opening_balance, '1000.00', 2));
        $this->assertSame(0, bccomp((string) $reconciliation->period_charges, '500.00', 2));
        $this->assertSame(0, bccomp((string) $reconciliation->period_collected, '100.00', 2));
        $this->assertSame(0, bccomp((string) $reconciliation->closing_balance, '1400.00', 2));
        $this->assertSame('NO-OPEN', $snapshot['debts'][0]['debt_code']);

        $later = $asOfDate->addDay()->setTime(10, 0);
        $this->payment($openingDebt, $accountant->id, '250.00', $later);
        DB::table('debts')->where('id', $openingDebt)->update(['paid' => '250.00']);

        $this->assertSame($snapshotBeforeLaterPayment, DB::table('debt_reconciliations')->where('id', $reconciliationId)->value('content_snapshot'));
        $this->assertSame(0, bccomp((string) DB::table('debt_reconciliations')->where('id', $reconciliationId)->value('closing_balance'), '1400.00', 2));
        $nextVersionId = $workflow->createSnapshot($customer->id, $asOfDate->toDateString(), $accountant->id);
        $this->assertDatabaseHas('debt_reconciliations', [
            'id' => $nextVersionId, 'previous_id' => $reconciliationId, 'version_number' => 2,
        ]);
    }

    public function test_customer_ownership_dispute_resolution_and_confirmation_are_separate_actions(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $otherCustomer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $debtId = $this->debt($customer->id, 'NO-RESPONSE-LEDGER', '300.00', CarbonImmutable::now()->subDay());
        $paymentsBefore = DB::table('payments')->count();
        $workflow = app(DebtReconciliationWorkflow::class);
        $id = $workflow->createSnapshot($customer->id, now()->toDateString(), $accountant->id);

        $this->actingAs($customer)->get('/my/debt-reconciliations/'.$id)->assertNotFound();
        $this->actingAs($otherCustomer)->get('/my/debt-reconciliations/'.$id)->assertNotFound();
        $this->actingAs($otherCustomer)->post('/my/debt-reconciliations/'.$id.'/respond', [
            'decision' => 'dispute', 'message' => 'Đây không phải hồ sơ của tôi.',
        ])->assertNotFound();

        $workflow->send($id, $accountant->id);
        $this->actingAs($customer)->get('/my/debt-reconciliations/'.$id)->assertOk()->assertSee('Xác nhận đúng');
        $this->actingAs($customer)->post('/my/debt-reconciliations/'.$id.'/respond', [
            'decision' => 'dispute', 'message' => '',
        ])->assertSessionHasErrors('message');
        $this->actingAs($customer)->post('/my/debt-reconciliations/'.$id.'/respond', [
            'decision' => 'dispute', 'message' => 'Tôi không nhận được khoản thu số 250.',
        ])->assertRedirect();
        $this->assertDatabaseHas('debt_reconciliations', ['id' => $id, 'status' => 'disputed', 'customer_confirmed_at' => null]);

        $this->actingAs($accountant)->post('/staff/debt-reconciliations/'.$id.'/resolve', ['message' => 'Đã kiểm tra chứng từ thu.'])->assertRedirect();
        $this->assertDatabaseHas('debt_reconciliations', ['id' => $id, 'status' => 'resolved', 'customer_confirmed_at' => null]);
        $this->actingAs($customer)->post('/my/debt-reconciliations/'.$id.'/respond', ['decision' => 'confirm'])->assertRedirect();
        $this->assertDatabaseHas('debt_reconciliations', ['id' => $id, 'status' => 'resolved']);
        $this->assertNotNull(DB::table('debt_reconciliations')->where('id', $id)->value('customer_confirmed_at'));
        $this->assertSame(3, DB::table('debt_reconciliation_messages')->where('reconciliation_id', $id)->count());
        $this->assertDatabaseHas('debts', ['id' => $debtId, 'amount' => '300.00', 'paid' => '0.00', 'status' => 'open']);
        $this->assertSame($paymentsBefore, DB::table('payments')->count());
        $this->actingAs($customer)->post('/my/debt-reconciliations/'.$id.'/respond', ['decision' => 'dispute', 'message' => 'Vẫn sai'])->assertSessionHasErrors('workflow');
    }

    public function test_later_return_is_reversed_for_an_earlier_snapshot_and_shown_as_a_period_adjustment(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $asOf = CarbonImmutable::now()->subDays(3);
        $approvedAt = $asOf->subDays(7);
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'DH-RECON-RETURN', 'customer_id' => $customer->id, 'status' => 'delivered',
            'total' => '1000.00', 'paid' => '0.00', 'delivery_address' => 'Công trình đối chiếu',
            'fulfillment_method' => 'delivery', 'requires_contract' => false, 'reviewed_at' => $approvedAt,
            'created_at' => $approvedAt->subMinute(), 'updated_at' => $approvedAt,
        ]);
        $debtId = DB::table('debts')->insertGetId([
            'code' => 'CN-RECON-RETURN', 'direction' => 'receivable', 'customer_id' => $customer->id,
            'order_id' => $orderId, 'amount' => '900.00', 'paid' => '0.00', 'status' => 'open',
            'created_at' => $approvedAt, 'updated_at' => $asOf->addDay(),
        ]);
        $productId = DB::table('products')->insertGetId(['code' => 'PR-RECON-RETURN', 'name' => 'Gạch trả', 'unit' => 'viên', 'price' => 100]);
        $returnAt = $asOf->addDay();
        DB::table('returns')->insert([
            'code' => 'TH-RECON-RETURN', 'order_id' => $orderId, 'product_id' => $productId,
            'customer_id' => $customer->id, 'quantity' => '1.000', 'credit_amount' => '100.00',
            'type' => 'return', 'status' => 'completed', 'reason' => 'Hàng trả sau ngày chốt',
            'completed_at' => $returnAt, 'created_at' => $approvedAt, 'updated_at' => $returnAt,
        ]);

        $workflow = app(DebtReconciliationWorkflow::class);
        $beforeReturnId = $workflow->createSnapshot($customer->id, $asOf->toDateString(), $accountant->id, $asOf->startOfMonth()->toDateString());
        $afterReturnDate = $asOf->addDays(2);
        $afterReturnId = $workflow->createSnapshot($customer->id, $afterReturnDate->toDateString(), $accountant->id, $asOf->startOfMonth()->toDateString());

        $this->assertSame(0, bccomp((string) DB::table('debt_reconciliations')->where('id', $beforeReturnId)->value('closing_balance'), '1000.00', 2));
        $this->assertSame(0, bccomp((string) DB::table('debt_reconciliations')->where('id', $afterReturnId)->value('closing_balance'), '900.00', 2));
        $this->assertSame(0, bccomp((string) DB::table('debt_reconciliations')->where('id', $afterReturnId)->value('period_returns'), '100.00', 2));
        $this->assertSame((string) $beforeReturnId, (string) DB::table('debt_reconciliations')->where('id', $afterReturnId)->value('previous_id'));
    }

    public function test_same_second_payment_and_return_keep_gross_period_amounts_and_credit_surplus_separate(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $asOf = CarbonImmutable::now()->subDay();
        $periodStart = $asOf->startOfMonth();
        $effectiveAt = $periodStart->addDays(2);
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'DH-RECON-SAME-SECOND', 'customer_id' => $customer->id, 'status' => 'delivered',
            'total' => '100.00', 'paid' => '80.00', 'delivery_address' => 'Công trình',
            'fulfillment_method' => 'delivery', 'requires_contract' => false, 'reviewed_at' => $effectiveAt,
            'created_at' => $effectiveAt->subMinute(), 'updated_at' => $effectiveAt,
        ]);
        $debtId = DB::table('debts')->insertGetId([
            'code' => 'CN-RECON-SAME-SECOND', 'direction' => 'receivable', 'customer_id' => $customer->id,
            'order_id' => $orderId, 'amount' => '20.00', 'paid' => '80.00', 'status' => 'settled',
            'created_at' => $effectiveAt, 'updated_at' => $effectiveAt,
        ]);
        $productId = DB::table('products')->insertGetId(['code' => 'PR-RECON-SAME', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        $returnAt = $effectiveAt->setMicrosecond(900000);
        $returnId = DB::table('returns')->insertGetId([
            'code' => 'TH-RECON-SAME', 'order_id' => $orderId, 'product_id' => $productId,
            'customer_id' => $customer->id, 'quantity' => '0.800', 'credit_amount' => '80.00',
            'type' => 'return', 'status' => 'completed', 'reason' => 'Hàng trả sau khi thu tiền',
            'completed_at' => $returnAt, 'created_at' => $effectiveAt, 'updated_at' => $returnAt,
        ]);
        DB::table('payments')->insert([
            'code' => 'TT-RECON-SAME', 'debt_id' => $debtId, 'recorded_by' => $accountant->id,
            'amount' => '80.00', 'method' => 'bank_transfer', 'paid_at' => $effectiveAt,
            'created_at' => $effectiveAt, 'updated_at' => $effectiveAt,
        ]);
        DB::table('customer_credits')->insert([
            'customer_id' => $customer->id, 'return_id' => $returnId, 'amount' => '60.00', 'status' => 'open',
            'created_at' => $returnAt, 'updated_at' => $returnAt,
        ]);

        $id = app(DebtReconciliationWorkflow::class)->createSnapshot(
            $customer->id, $asOf->toDateString(), $accountant->id, $periodStart->toDateString()
        );
        $snapshot = json_decode(DB::table('debt_reconciliations')->where('id', $id)->value('content_snapshot'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, bccomp((string) DB::table('debt_reconciliations')->where('id', $id)->value('period_collected'), '80.00', 2));
        $this->assertSame(0, bccomp((string) DB::table('debt_reconciliations')->where('id', $id)->value('period_returns'), '80.00', 2));
        $this->assertSame(0, bccomp((string) DB::table('debt_reconciliations')->where('id', $id)->value('period_credit_issued'), '60.00', 2));
        $this->assertSame(0, bccomp((string) DB::table('debt_reconciliations')->where('id', $id)->value('closing_balance'), '0.00', 2));
        $this->assertSame(4, count($snapshot['debts'][0]['transactions']));
        $this->assertSame('credit_issue', $snapshot['debts'][0]['transactions'][3]['type']);
    }

    public function test_only_accounting_and_admin_can_create_or_resolve_reconciliations(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $warehouse = User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        $this->actingAs($warehouse)->get('/staff/debt-reconciliations')->assertForbidden();
        $this->actingAs($warehouse)->post('/staff/debt-reconciliations', [
            'customer_id' => $customer->id, 'as_of_date' => now()->toDateString(),
        ])->assertForbidden();
        $this->actingAs($warehouse)->get('/my/debt-reconciliations')->assertForbidden();
    }

    public function test_snapshot_fails_closed_for_legacy_active_order_without_a_recorded_approval_time(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $createdAt = now()->subMonth();
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'DH-LEGACY-1', 'customer_id' => $customer->id, 'contract_id' => null,
            'status' => 'ready_to_issue', 'total' => '200.00', 'paid' => '0.00',
            'delivery_address' => 'Công trình cũ', 'fulfillment_method' => 'delivery', 'requires_contract' => false,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        DB::table('debts')->insert([
            'code' => 'CN-LEGACY-1', 'direction' => 'receivable', 'customer_id' => $customer->id,
            'order_id' => $orderId, 'amount' => '200.00', 'paid' => '0.00', 'status' => 'open',
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        $this->actingAs($accountant)->post('/staff/debt-reconciliations', [
            'customer_id' => $customer->id, 'period_start' => now()->startOfMonth()->toDateString(),
            'as_of_date' => now()->toDateString(),
        ])->assertSessionHasErrors('as_of_date');
        $this->assertDatabaseCount('debt_reconciliations', 0);
    }

    public function test_admin_can_list_and_create_customer_debt_reconciliations(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $this->actingAs($admin)->get('/staff/debt-reconciliations')->assertOk();
        $this->actingAs($admin)->post('/staff/debt-reconciliations', [
            'customer_id' => $customer->id, 'period_start' => now()->startOfMonth()->toDateString(),
            'as_of_date' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertDatabaseHas('debt_reconciliations', ['customer_id' => $customer->id, 'created_by' => $admin->id]);
    }

    public function test_tampered_snapshot_is_not_shown_sent_or_confirmed(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $workflow = app(DebtReconciliationWorkflow::class);
        $id = $workflow->createSnapshot($customer->id, now()->toDateString(), $accountant->id);
        $workflow->send($id, $accountant->id);
        DB::table('debt_reconciliations')->where('id', $id)->update(['content_snapshot' => '{"tampered":true}']);

        $this->actingAs($accountant)->get('/staff/debt-reconciliations/'.$id)->assertStatus(409);
        $this->actingAs($customer)->get('/my/debt-reconciliations/'.$id)->assertStatus(409);
        $this->actingAs($customer)->post('/my/debt-reconciliations/'.$id.'/respond', ['decision' => 'confirm'])->assertStatus(409);
        $this->assertDatabaseHas('debt_reconciliations', ['id' => $id, 'status' => 'sent', 'customer_confirmed_at' => null]);
        $this->assertDatabaseCount('debt_reconciliation_messages', 0);
    }

    public function test_tampered_draft_snapshot_cannot_be_sent(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $id = app(DebtReconciliationWorkflow::class)->createSnapshot($customer->id, now()->toDateString(), $accountant->id);
        DB::table('debt_reconciliations')->where('id', $id)->update(['content_snapshot' => '{"tampered":true}']);

        $this->actingAs($accountant)->post('/staff/debt-reconciliations/'.$id.'/send')->assertStatus(409);
        $this->assertDatabaseHas('debt_reconciliations', ['id' => $id, 'status' => 'draft', 'sent_at' => null]);
    }

    public function test_tampered_disputed_snapshot_cannot_be_resolved(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $workflow = app(DebtReconciliationWorkflow::class);
        $id = $workflow->createSnapshot($customer->id, now()->toDateString(), $accountant->id);
        $workflow->send($id, $accountant->id);
        $workflow->respond($id, $customer->id, 'dispute', 'Tôi đề nghị kiểm tra lại.');
        DB::table('debt_reconciliations')->where('id', $id)->update(['content_snapshot' => '{"tampered":true}']);

        $this->actingAs($accountant)->post('/staff/debt-reconciliations/'.$id.'/resolve', [
            'message' => 'Đã kiểm tra chứng từ.',
        ])->assertStatus(409);
        $this->assertDatabaseHas('debt_reconciliations', ['id' => $id, 'status' => 'disputed']);
        $this->assertSame(1, DB::table('debt_reconciliation_messages')->where('reconciliation_id', $id)->count());
    }

    public function test_customer_version_number_is_unique_in_the_database(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $id = app(DebtReconciliationWorkflow::class)->createSnapshot($customer->id, now()->toDateString(), $accountant->id);
        $duplicate = (array) DB::table('debt_reconciliations')->where('id', $id)->first();
        unset($duplicate['id']);
        $rejected = false;
        try {
            DB::table('debt_reconciliations')->insert($duplicate);
        } catch (QueryException) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'The database must reject duplicate snapshot versions for one customer.');
    }

    private function debt(int $customerId, string $code, string $amount, CarbonImmutable $createdAt): int
    {
        return DB::table('debts')->insertGetId([
            'code' => $code, 'direction' => 'receivable', 'customer_id' => $customerId,
            'amount' => $amount, 'paid' => '0.00', 'status' => 'open',
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function payment(int $debtId, int $actorId, string $amount, CarbonImmutable $paidAt): int
    {
        return DB::table('payments')->insertGetId([
            'code' => 'TT-'.uniqid(), 'debt_id' => $debtId, 'recorded_by' => $actorId,
            'amount' => $amount, 'method' => 'bank_transfer', 'paid_at' => $paidAt,
            'created_at' => $paidAt, 'updated_at' => $paidAt,
        ]);
    }
}
