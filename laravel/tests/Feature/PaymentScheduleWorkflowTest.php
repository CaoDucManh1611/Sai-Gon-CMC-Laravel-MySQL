<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CommerceWorkflow;
use App\Services\FulfillmentWorkflow;
use App\Services\PaymentScheduleWorkflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentScheduleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_payment_is_allocated_across_installments_and_only_required_installments_gate_release(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'DH-PLAN-100', 'customer_id' => $customer->id, 'status' => 'awaiting_payment',
            'total' => '100.00', 'delivery_address' => 'Công trình', 'fulfillment_method' => 'delivery',
            'requires_contract' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $debtId = DB::table('debts')->insertGetId([
            'code' => 'CN-PLAN-100', 'direction' => 'receivable', 'customer_id' => $customer->id,
            'order_id' => $orderId, 'amount' => '100.00', 'paid' => '0.00', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $installments = [
            ['sequence' => 1, 'label' => 'Đợt 1', 'amount' => '30.00', 'trigger' => 'before_issue', 'due_on' => null, 'days_after_trigger' => null, 'required_before_issue' => true],
            ['sequence' => 2, 'label' => 'Đợt 2', 'amount' => '50.00', 'trigger' => 'before_issue', 'due_on' => null, 'days_after_trigger' => null, 'required_before_issue' => true],
            ['sequence' => 3, 'label' => 'Đợt 3', 'amount' => '20.00', 'trigger' => 'after_handover', 'due_on' => null, 'days_after_trigger' => 7, 'required_before_issue' => false],
        ];
        $workflow = app(PaymentScheduleWorkflow::class);
        $workflow->replaceDraftSchedule($debtId, $installments, $accountant->id);

        $this->assertFalse($workflow->isReleaseEligible($orderId));
        $firstPayment = $this->payment($debtId, $accountant->id, '40.00', 'PAY-PLAN-1');
        $workflow->allocatePayment($firstPayment);
        $allocations = DB::table('payment_allocations')->where('payment_id', $firstPayment)->orderBy('installment_id')->get();
        $this->assertCount(2, $allocations);
        $this->assertSame('30.00', bcadd((string) $allocations[0]->amount, '0', 2));
        $this->assertSame('10.00', bcadd((string) $allocations[1]->amount, '0', 2));
        $this->assertFalse($workflow->isReleaseEligible($orderId));

        $secondPayment = $this->payment($debtId, $accountant->id, '40.00', 'PAY-PLAN-2');
        $workflow->allocatePayment($secondPayment);
        $this->assertTrue($workflow->isReleaseEligible($orderId));

        DB::table('orders')->where('id', $orderId)->update(['status' => 'delivered']);
        DB::table('shipments')->insert([
            'code' => 'GN-PLAN-100', 'order_id' => $orderId, 'status' => 'delivered',
            'delivered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $workflow->activateHandoverTerms($orderId);
        $this->assertDatabaseHas('payment_installments', [
            'debt_id' => $debtId, 'sequence' => 3, 'due_on' => now()->addDays(7)->toDateString(),
        ]);
        $workflow->activateHandoverTerms($orderId);
        $this->assertSame(1, DB::table('payment_installments')->where('debt_id', $debtId)
            ->where('sequence', 3)->whereNotNull('activated_at')->count());
    }

    public function test_schedule_total_must_match_debt_and_cannot_be_replaced_after_allocation(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $debtId = DB::table('debts')->insertGetId([
            'code' => 'CN-PLAN-VALIDATE', 'direction' => 'receivable', 'customer_id' => $customer->id,
            'amount' => '100.00', 'paid' => '0.00', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $workflow = app(PaymentScheduleWorkflow::class);
        $schedule = [[
            'sequence' => 1, 'label' => 'Thanh toán đủ', 'amount' => '100.00', 'trigger' => 'before_issue',
            'due_on' => null, 'days_after_trigger' => null, 'required_before_issue' => true,
        ]];

        try {
            $workflow->replaceDraftSchedule($debtId, [
                array_merge($schedule[0], ['amount' => '99.00']),
            ], $accountant->id);
            $this->fail('Không được lưu lịch có tổng khác công nợ.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payment_installments', 0);
        }

        $workflow->replaceDraftSchedule($debtId, $schedule, $accountant->id);
        $paymentId = $this->payment($debtId, $accountant->id, '10.00', 'PAY-PLAN-LOCK');
        $workflow->allocatePayment($paymentId);

        try {
            $workflow->replaceDraftSchedule($debtId, $schedule, $accountant->id);
            $this->fail('Không được thay lịch sau khi đã phân bổ tiền.');
        } catch (ValidationException) {
            $this->assertSame('100.00', bcadd((string) DB::table('payment_installments')->where('debt_id', $debtId)->value('amount'), '0', 2));
        }
    }

    public function test_payment_retry_with_same_key_creates_one_payment_and_never_reopens_a_delivered_order(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        [$orderId, $debtId] = $this->orderAndDebt($customer, 'delivered');
        $key = (string) Str::uuid();
        $workflow = app(CommerceWorkflow::class);

        $first = $workflow->recordPayment($debtId, $accountant->id, '25.00', 'cash', 'THU-1', $key);
        $retry = $workflow->recordPayment($debtId, $accountant->id, '25.00', 'cash', 'THU-1', $key);

        $this->assertSame($first, $retry);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'delivered']);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $first, 'amount' => 25]);

        try {
            $workflow->recordPayment($debtId, $accountant->id, '26.00', 'cash', 'THU-1', $key);
            $this->fail('Mã gửi lại không được tái sử dụng cho nội dung khác.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 1);
        }

        try {
            $workflow->recordPayment($debtId, $accountant->id, '25.001', 'cash', 'THU-2', (string) Str::uuid());
            $this->fail('Không được lưu tiền có độ chính xác lớn hơn hai chữ số thập phân.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 1);
        }
    }

    public function test_credit_offset_is_allocated_without_creating_a_cash_payment(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        [, $debtId, $orderId] = $this->orderAndDebt($customer, 'delivered');
        $productId = DB::table('products')->insertGetId(['code' => 'PLAN-CREDIT', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        $returnId = DB::table('returns')->insertGetId([
            'code' => 'DT-PLAN-CREDIT', 'order_id' => $orderId, 'product_id' => $productId,
            'customer_id' => $customer->id, 'quantity' => 1, 'credit_amount' => 25,
            'type' => 'return', 'status' => 'completed', 'reason' => 'Hoàn dư ghi có',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $creditId = DB::table('customer_credits')->insertGetId([
            'customer_id' => $customer->id, 'return_id' => $returnId, 'amount' => 25,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(CommerceWorkflow::class)->useCustomerCredit($creditId, $accountant->id, '25.00', 'offset', $debtId,
            idempotencyKey: (string) Str::uuid());

        $this->assertDatabaseCount('payments', 0);
        $movementId = DB::table('customer_credit_movements')->value('id');
        $this->assertDatabaseHas('payment_allocations', ['credit_movement_id' => $movementId, 'amount' => 25]);
        $this->assertDatabaseHas('debts', ['id' => $debtId, 'paid' => 25]);
    }

    public function test_replaying_customer_credit_offset_with_same_key_does_not_apply_it_twice(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $otherAccountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        [, $debtId, $orderId] = $this->orderAndDebt($customer, 'delivered');
        $productId = DB::table('products')->insertGetId(['code' => 'PLAN-CREDIT-IDEMPOTENT', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        $returnId = DB::table('returns')->insertGetId([
            'code' => 'DT-CREDIT-IDEMPOTENT', 'order_id' => $orderId, 'product_id' => $productId,
            'customer_id' => $customer->id, 'quantity' => 1, 'credit_amount' => 30,
            'type' => 'return', 'status' => 'completed', 'reason' => 'Ghi có dùng kiểm thử',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $creditId = DB::table('customer_credits')->insertGetId([
            'customer_id' => $customer->id, 'return_id' => $returnId, 'amount' => 30,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $workflow = app(CommerceWorkflow::class);
        $idempotencyKey = (string) Str::uuid();

        $movementId = $workflow->useCustomerCredit($creditId, $accountant->id, '10.00', 'offset', $debtId, null, null, $idempotencyKey);
        $replayedMovementId = $workflow->useCustomerCredit($creditId, $accountant->id, '10.00', 'offset', $debtId, null, null, $idempotencyKey);

        $this->assertSame($movementId, $replayedMovementId);
        $this->assertDatabaseCount('customer_credit_movements', 1);
        $this->assertDatabaseHas('debts', ['id' => $debtId, 'paid' => 10]);
        $this->assertSame('10.00', bcadd((string) DB::table('payment_allocations')->sum('amount'), '0', 2));

        $differentPayloadRejected = false;
        try {
            $workflow->useCustomerCredit($creditId, $accountant->id, '11.00', 'offset', $debtId, null, null, $idempotencyKey);
        } catch (ValidationException) {
            $differentPayloadRejected = true;
        }
        $this->assertTrue($differentPayloadRejected);

        $differentActorRejected = false;
        try {
            $workflow->useCustomerCredit($creditId, $otherAccountant->id, '10.00', 'offset', $debtId, null, null, $idempotencyKey);
        } catch (ValidationException) {
            $differentActorRejected = true;
        }
        $this->assertTrue($differentActorRejected);
        $this->assertDatabaseCount('customer_credit_movements', 1);
    }

    public function test_staff_can_submit_a_consistent_contract_schedule_and_staff_customer_can_view_it(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $quoteId = DB::table('quotes')->insertGetId([
            'code' => 'BG-PLAN-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id,
            'status' => 'accepted', 'delivery_address' => 'Công trình', 'total' => '100.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'DH-CONTRACT-'.Str::upper(Str::random(6)), 'quote_id' => $quoteId,
            'customer_id' => $customer->id, 'status' => 'awaiting_contract', 'total' => '100.00',
            'paid' => '0.00', 'delivery_address' => 'Công trình', 'fulfillment_method' => 'delivery',
            'requires_contract' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $debtId = DB::table('debts')->insertGetId([
            'code' => 'CN-CONTRACT-'.Str::upper(Str::random(6)), 'direction' => 'receivable',
            'customer_id' => $customer->id, 'order_id' => $orderId, 'amount' => '100.00',
            'paid' => '0.00', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = [
            'deposit_required' => '30.00', 'payment_terms' => 'Thanh toán theo ba đợt',
            'delivery_terms' => 'Giao tại công trình',
            'installments' => [
                ['sequence' => 1, 'label' => 'Đợt ký', 'amount' => '30.00', 'trigger' => 'before_issue', 'required_before_issue' => '1'],
                ['sequence' => 2, 'label' => 'Đợt bàn giao', 'amount' => '70.00', 'trigger' => 'after_handover', 'days_after_trigger' => '7'],
            ],
        ];

        $this->actingAs($sales)->post('/staff/orders/'.$orderId.'/contracts', $payload)->assertRedirect();

        $contractId = DB::table('contracts')->where('order_id', $orderId)->value('id');
        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'deposit_required' => 30]);
        $this->assertSame('100.00', bcadd((string) DB::table('payment_installments')->where('debt_id', $debtId)->sum('amount'), '0', 2));
        $this->actingAs($sales)->get('/staff/work/contracts')->assertOk()
            ->assertSee('Lịch và tình trạng thanh toán')->assertSee('Đợt bàn giao');
        DB::table('contracts')->where('id', $contractId)->update(['status' => 'approved']);
        $this->actingAs($customer)->get('/my/contracts')->assertOk()
            ->assertSee('Lịch thanh toán')->assertSee('Trước khi xuất')->assertSee('Sau bàn giao');
    }

    public function test_quote_only_contract_keeps_its_draft_schedule_until_order_and_debt_are_created(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $quoteId = DB::table('quotes')->insertGetId([
            'code' => 'BG-DRAFT-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id,
            'status' => 'accepted', 'delivery_address' => 'Công trình', 'total' => '100.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'code' => 'HD-DRAFT-'.Str::upper(Str::random(6)), 'quote_id' => $quoteId,
            'created_by' => $sales->id, 'status' => 'draft', 'total' => '100.00', 'deposit_required' => '0.00',
            'payment_terms' => 'Cũ', 'delivery_terms' => 'Cũ', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = [
            'deposit_required' => '30.00', 'payment_terms' => 'Ba đợt', 'delivery_terms' => 'Giao công trình',
            'installments' => [
                ['sequence' => 1, 'label' => 'Trước xuất', 'amount' => '30.00', 'trigger' => 'before_issue', 'required_before_issue' => '1'],
                ['sequence' => 2, 'label' => 'Sau bàn giao', 'amount' => '70.00', 'trigger' => 'after_handover', 'days_after_trigger' => '7'],
            ],
        ];

        $this->actingAs($sales)->put('/staff/contracts/'.$contractId, $payload)->assertRedirect();
        $storedSchedule = json_decode((string) DB::table('contracts')->where('id', $contractId)->value('payment_schedule_draft'), true);
        $this->assertSame(7, $storedSchedule[1]['days_after_trigger']);
        DB::table('contracts')->where('id', $contractId)->update([
            'status' => 'approved', 'customer_confirmed_at' => now(),
        ]);
        $this->actingAs($customer)->get('/my/contracts')->assertOk()
            ->assertSee('Lịch thanh toán đã đề xuất')->assertSee('7 ngày');

        $orderId = app(CommerceWorkflow::class)->signContract($contractId, 'contracts/legacy-signed.pdf', $sales->id);

        $debtId = DB::table('debts')->where('order_id', $orderId)->value('id');
        $this->assertDatabaseHas('payment_installments', [
            'debt_id' => $debtId, 'sequence' => 2, 'amount' => 70, 'days_after_trigger' => 7,
        ]);
        $this->assertFalse(app(PaymentScheduleWorkflow::class)->isReleaseEligible($orderId));
    }

    public function test_legacy_handover_installment_without_a_known_due_day_is_flagged_for_confirmation(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $quoteId = DB::table('quotes')->insertGetId([
            'code' => 'BG-LEGACY-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id,
            'status' => 'accepted', 'delivery_address' => 'Công trình', 'total' => '100.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'DH-LEGACY-'.Str::upper(Str::random(6)), 'quote_id' => $quoteId,
            'customer_id' => $customer->id, 'status' => 'awaiting_payment', 'total' => '100.00',
            'paid' => '0.00', 'delivery_address' => 'Công trình', 'fulfillment_method' => 'delivery',
            'requires_contract' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'code' => 'HD-LEGACY-'.Str::upper(Str::random(6)), 'quote_id' => $quoteId,
            'order_id' => $orderId, 'created_by' => $customer->id, 'status' => 'signed',
            'total' => '100.00', 'deposit_required' => '30.00', 'payment_terms' => 'Đợt cọc và còn lại',
            'delivery_terms' => 'Giao công trình', 'signed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->where('id', $orderId)->update(['contract_id' => $contractId]);
        $debtId = DB::table('debts')->insertGetId([
            'code' => 'CN-LEGACY-'.Str::upper(Str::random(6)), 'direction' => 'receivable',
            'customer_id' => $customer->id, 'order_id' => $orderId, 'amount' => '100.00',
            'paid' => '0.00', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $workflow = app(PaymentScheduleWorkflow::class);
        $workflow->ensureLegacySchedule($debtId);
        $rows = $workflow->summariesForDebts([$debtId])->get($debtId);
        $html = view('shared.payment-schedule-table', ['scheduleRows' => $rows])->render();

        $this->assertStringContainsString('Cần xác nhận hạn theo hợp đồng đã ký', $html);
    }

    public function test_payment_allocation_preview_keeps_valid_json_after_html_attribute_parsing(): void
    {
        $html = view('staff.partials.payment-allocation-preview', [
            'scheduleRows' => collect([(object) [
                'sequence' => 1, 'label' => 'Đợt "A & B"', 'remaining_amount' => '100.00',
            ]]),
        ])->render();
        $document = new \DOMDocument;
        @$document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $preview = (new \DOMXPath($document))->query('//*[@data-payment-allocation-preview]')->item(0);

        $this->assertNotNull($preview);
        $this->assertSame([[
            'sequence' => 1, 'label' => 'Đợt "A & B"', 'remaining' => 100,
        ]], json_decode($preview->getAttribute('data-installments'), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_migration_recreates_idempotency_unique_index_when_column_already_exists(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
        });

        $this->assertFalse(Schema::hasIndex('payments', ['idempotency_key'], 'unique'));
        $migration = require database_path('migrations/2026_10_28_140000_add_payment_installment_schedules.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex('payments', ['idempotency_key'], 'unique'));
    }

    public function test_migration_restores_schedule_constraints_when_tables_already_exist(): void
    {
        Schema::table('contracts', fn (Blueprint $table) => $table->dropForeign(['payment_schedule_draft_by']));
        Schema::table('payment_installments', function (Blueprint $table) {
            $table->dropUnique(['debt_id', 'sequence']);
            $table->dropForeign(['debt_id']);
        });
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropUnique(['installment_id', 'payment_id']);
            $table->dropForeign(['installment_id']);
        });
        Schema::table('installment_adjustments', function (Blueprint $table) {
            $table->dropUnique(['return_id', 'installment_id']);
            $table->dropForeign(['return_id']);
        });

        $migration = require database_path('migrations/2026_10_28_140000_add_payment_installment_schedules.php');
        $migration->up();

        $this->assertTrue(Schema::hasForeignKey('contracts', ['payment_schedule_draft_by']));
        $this->assertTrue(Schema::hasIndex('payment_installments', ['debt_id', 'sequence'], 'unique'));
        $this->assertTrue(Schema::hasForeignKey('payment_installments', ['debt_id']));
        $this->assertTrue(Schema::hasIndex('payment_allocations', ['installment_id', 'payment_id'], 'unique'));
        $this->assertTrue(Schema::hasForeignKey('payment_allocations', ['installment_id']));
        $this->assertTrue(Schema::hasIndex('installment_adjustments', ['return_id', 'installment_id'], 'unique'));
        $this->assertTrue(Schema::hasForeignKey('installment_adjustments', ['return_id']));
    }

    public function test_legacy_backfill_reduces_only_unpaid_amount_for_a_completed_return(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        [$orderId, $debtId] = $this->orderAndDebt($customer, 'delivered');
        $productId = DB::table('products')->insertGetId(['code' => 'PLAN-LEGACY-RETURN', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        $returnId = DB::table('returns')->insertGetId([
            'code' => 'DT-LEGACY-RETURN', 'order_id' => $orderId, 'product_id' => $productId,
            'customer_id' => $customer->id, 'quantity' => 1, 'credit_amount' => 20,
            'type' => 'return', 'status' => 'completed', 'reason' => 'Trả hàng sau khi đã thu một phần',
            'processed_by' => $accountant->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $paymentId = $this->payment($debtId, $accountant->id, '90.00', 'PAY-LEGACY-RETURN');
        DB::table('debts')->where('id', $debtId)->update(['amount' => 80, 'paid' => 90, 'status' => 'settled']);

        Schema::dropIfExists('installment_adjustments');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_installments');
        $migration = require database_path('migrations/2026_10_28_140000_add_payment_installment_schedules.php');
        $migration->up();
        $migration->up();

        $principal = (string) DB::table('payment_installments')->where('debt_id', $debtId)->sum('amount');
        $adjustments = (string) DB::table('installment_adjustments')->where('return_id', $returnId)->sum('amount');
        $allocated = (string) DB::table('payment_allocations')->where('payment_id', $paymentId)->sum('amount');

        $this->assertSame('90.00', bcadd($principal, $adjustments, 2));
        $this->assertSame('90.00', bcadd($allocated, '0', 2));
        $this->assertSame(1, DB::table('payment_installments')->where('debt_id', $debtId)->count());
    }

    public function test_legacy_backfill_attributes_return_adjustments_by_completion_time_not_id(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        [$orderId, $debtId] = $this->orderAndDebt($customer, 'delivered');
        $quoteId = DB::table('quotes')->insertGetId([
            'code' => 'BG-MULTI-RETURN-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id,
            'status' => 'accepted', 'delivery_address' => 'Công trình', 'total' => '100.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'code' => 'HD-MULTI-RETURN-'.Str::upper(Str::random(6)), 'quote_id' => $quoteId,
            'order_id' => $orderId, 'created_by' => $accountant->id, 'status' => 'signed',
            'total' => '100.00', 'deposit_required' => '30.00', 'payment_terms' => 'Hai đợt',
            'delivery_terms' => 'Giao công trình', 'signed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->where('id', $orderId)->update(['contract_id' => $contractId]);
        $productId = DB::table('products')->insertGetId(['code' => 'PLAN-MULTI-RETURN', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        if (! Schema::hasColumn('returns', 'completed_at')) {
            Schema::table('returns', fn (Blueprint $table) => $table->timestamp('completed_at', 6)->nullable());
        }
        $settlementSecond = now()->startOfSecond();
        $laterCompletion = $settlementSecond->copy()->addMicroseconds(800000)->format('Y-m-d H:i:s.u');
        $earlierCompletion = $settlementSecond->copy()->addMicroseconds(200000)->format('Y-m-d H:i:s.u');
        $sameUpdateTime = $settlementSecond->format('Y-m-d H:i:s');
        $laterReturnId = DB::table('returns')->insertGetId([
            'code' => 'DT-MULTI-LATER', 'order_id' => $orderId, 'product_id' => $productId,
            'customer_id' => $customer->id, 'processed_by' => $accountant->id, 'quantity' => 1,
            'credit_amount' => 15, 'type' => 'return', 'status' => 'completed', 'reason' => 'Trả sau',
            'completed_at' => $laterCompletion, 'created_at' => now()->subDays(2), 'updated_at' => $sameUpdateTime,
        ]);
        $earlierReturnId = DB::table('returns')->insertGetId([
            'code' => 'DT-MULTI-EARLIER', 'order_id' => $orderId, 'product_id' => $productId,
            'customer_id' => $customer->id, 'processed_by' => $accountant->id, 'quantity' => 1,
            'credit_amount' => 15, 'type' => 'return', 'status' => 'completed', 'reason' => 'Trả trước',
            'completed_at' => $earlierCompletion, 'created_at' => now()->subDay(), 'updated_at' => $sameUpdateTime,
        ]);
        DB::table('customer_credits')->insert([
            ['customer_id' => $customer->id, 'return_id' => $laterReturnId, 'amount' => 15, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => $customer->id, 'return_id' => $earlierReturnId, 'amount' => 5, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $paymentId = $this->payment($debtId, $accountant->id, '90.00', 'PAY-MULTI-RETURN');
        DB::table('debts')->where('id', $debtId)->update(['amount' => 70, 'paid' => 90, 'status' => 'settled']);

        Schema::dropIfExists('installment_adjustments');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_installments');
        $migration = require database_path('migrations/2026_10_28_140000_add_payment_installment_schedules.php');
        $migration->up();
        $migration->up();

        $this->assertSame(-10, DB::table('installment_adjustments')->where('return_id', $earlierReturnId)->sum('amount'));
        $this->assertSame(0, DB::table('installment_adjustments')->where('return_id', $laterReturnId)->count());
        $this->assertSame(90, DB::table('payment_allocations')->where('payment_id', $paymentId)->sum('amount'));
    }

    public function test_legacy_backfill_stops_when_legacy_return_order_is_ambiguous_and_unpaid_amount_remains(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        [$orderId, $debtId] = $this->orderAndDebt($customer, 'delivered');
        $productId = DB::table('products')->insertGetId(['code' => 'PLAN-AMBIG-RETURN', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        $sameSecond = now()->startOfSecond()->format('Y-m-d H:i:s');
        $returnIds = [];
        foreach ([1, 2] as $sequence) {
            $returnIds[] = DB::table('returns')->insertGetId([
                'code' => 'DT-AMBIG-'.$sequence, 'order_id' => $orderId, 'product_id' => $productId,
                'customer_id' => $customer->id, 'processed_by' => $accountant->id, 'quantity' => 1,
                'credit_amount' => 15, 'type' => 'return', 'status' => 'completed',
                'reason' => 'Phiếu lịch sử thiếu thời điểm hoàn tất chính xác',
                'created_at' => now()->subDays($sequence), 'updated_at' => $sameSecond,
            ]);
        }
        DB::table('customer_credits')->insert(array_map(fn ($returnId) => [
            'customer_id' => $customer->id, 'return_id' => $returnId, 'amount' => 15,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ], $returnIds));
        $this->payment($debtId, $accountant->id, '90.00', 'PAY-AMBIG-RETURN');
        DB::table('debts')->where('id', $debtId)->update(['amount' => 70, 'paid' => 90, 'status' => 'settled']);

        Schema::dropIfExists('installment_adjustments');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_installments');
        $migration = require database_path('migrations/2026_10_28_140000_add_payment_installment_schedules.php');

        $manualReconciliationRequired = false;
        try {
            $migration->up();
        } catch (\RuntimeException $exception) {
            $manualReconciliationRequired = true;
            $this->assertStringContainsString('đối soát thủ công', $exception->getMessage());
        }

        $this->assertTrue($manualReconciliationRequired, 'Không được tự suy đoán thứ tự hoàn tất của hai phiếu trả lịch sử cùng giây.');
        $this->assertSame(0, DB::table('payment_installments')->where('debt_id', $debtId)->count());
        $this->assertSame(0, DB::table('payment_allocations')->where('payment_id', DB::table('payments')->where('debt_id', $debtId)->value('id'))->count());
        $this->assertSame(0, DB::table('installment_adjustments')->whereIn('return_id', $returnIds)->count());
    }

    public function test_return_adjusts_latest_installments_once_and_keeps_historic_allocations(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        [$orderId, $debtId] = $this->orderAndDebt($customer, 'delivered');
        $productId = DB::table('products')->insertGetId(['code' => 'PLAN-RETURN', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        $returnId = DB::table('returns')->insertGetId([
            'code' => 'DT-PLAN-RETURN', 'order_id' => $orderId, 'product_id' => $productId,
            'customer_id' => $customer->id, 'quantity' => 1, 'credit_amount' => 80,
            'type' => 'return', 'status' => 'inspected', 'reason' => 'Trả hàng',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $workflow = app(PaymentScheduleWorkflow::class);
        $workflow->replaceDraftSchedule($debtId, [
            ['sequence' => 1, 'label' => 'Đợt 1', 'amount' => '30.00', 'trigger' => 'before_issue', 'due_on' => null, 'days_after_trigger' => null, 'required_before_issue' => true],
            ['sequence' => 2, 'label' => 'Đợt 2', 'amount' => '50.00', 'trigger' => 'before_issue', 'due_on' => null, 'days_after_trigger' => null, 'required_before_issue' => true],
            ['sequence' => 3, 'label' => 'Đợt 3', 'amount' => '20.00', 'trigger' => 'after_handover', 'due_on' => null, 'days_after_trigger' => 7, 'required_before_issue' => false],
        ], $accountant->id);
        $paymentId = $this->payment($debtId, $accountant->id, '100.00', 'PAY-PLAN-RETURN');
        DB::table('debts')->where('id', $debtId)->update(['paid' => 100, 'status' => 'settled']);
        $workflow->allocatePayment($paymentId);

        app(FulfillmentWorkflow::class)->settleReturn($returnId, $accountant->id);

        $completedAt = DB::table('returns')->where('id', $returnId)->value('completed_at');
        $this->assertMatchesRegularExpression('/\.\d{6}$/', (string) $completedAt);
        $schedulePrincipal = (string) DB::table('payment_installments')->where('debt_id', $debtId)->sum('amount');
        $adjustedPrincipal = (string) DB::table('installment_adjustments')->whereIn('installment_id',
            DB::table('payment_installments')->where('debt_id', $debtId)->select('id'))->sum('amount');
        $this->assertSame('100.00', bcadd($schedulePrincipal, $adjustedPrincipal, 2));
        $this->assertDatabaseCount('installment_adjustments', 0);
        $this->assertSame(3, DB::table('payment_allocations')->where('payment_id', $paymentId)->count());
        $this->assertDatabaseHas('debts', ['id' => $debtId, 'amount' => 20, 'paid' => 100]);
        $this->assertDatabaseHas('customer_credits', ['return_id' => $returnId, 'amount' => 80]);

        try {
            $workflow->adjustForReturn($returnId, $debtId, $accountant->id, '80.00', 'Retry');
            $this->fail('Không được áp dụng cùng phiếu trả hàng hai lần.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('installment_adjustments')->where('return_id', $returnId)->count());
        }
    }

    public function test_return_reduces_only_unpaid_installments_and_credits_the_collected_excess(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        [$orderId, $debtId] = $this->orderAndDebt($customer, 'delivered');
        $productId = DB::table('products')->insertGetId(['code' => 'PLAN-PARTIAL-RETURN', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        $returnId = DB::table('returns')->insertGetId([
            'code' => 'DT-PARTIAL-RETURN', 'order_id' => $orderId, 'product_id' => $productId,
            'customer_id' => $customer->id, 'quantity' => 1, 'credit_amount' => 20,
            'type' => 'return', 'status' => 'inspected', 'reason' => 'Trả hàng sau khi đã thu một phần',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $schedule = app(PaymentScheduleWorkflow::class);
        $schedule->replaceDraftSchedule($debtId, [
            ['sequence' => 1, 'label' => 'Đợt 1', 'amount' => '30.00', 'trigger' => 'before_issue', 'due_on' => null, 'days_after_trigger' => null, 'required_before_issue' => true],
            ['sequence' => 2, 'label' => 'Đợt 2', 'amount' => '50.00', 'trigger' => 'before_issue', 'due_on' => null, 'days_after_trigger' => null, 'required_before_issue' => true],
            ['sequence' => 3, 'label' => 'Đợt 3', 'amount' => '20.00', 'trigger' => 'after_handover', 'due_on' => null, 'days_after_trigger' => 7, 'required_before_issue' => false],
        ], $accountant->id);
        $paymentId = $this->payment($debtId, $accountant->id, '90.00', 'PAY-PARTIAL-RETURN');
        DB::table('debts')->where('id', $debtId)->update(['paid' => 90, 'status' => 'open']);
        $schedule->allocatePayment($paymentId);

        app(FulfillmentWorkflow::class)->settleReturn($returnId, $accountant->id);

        $adjustmentTotal = (string) DB::table('installment_adjustments')->where('return_id', $returnId)->sum('amount');
        $scheduledAfterAdjustment = bcadd(
            (string) DB::table('payment_installments')->where('debt_id', $debtId)->sum('amount'),
            $adjustmentTotal,
            2
        );
        $allocatedTotal = (string) DB::table('payment_allocations')->where('payment_id', $paymentId)->sum('amount');

        $this->assertSame('-10.00', bcadd($adjustmentTotal, '0', 2));
        $this->assertSame('90.00', bcadd($scheduledAfterAdjustment, '0', 2));
        $this->assertSame('90.00', bcadd($allocatedTotal, '0', 2));
        $this->assertDatabaseHas('customer_credits', ['return_id' => $returnId, 'amount' => 10]);
    }

    private function payment(int $debtId, int $actorId, string $amount, string $code): int
    {
        return DB::table('payments')->insertGetId([
            'code' => $code, 'debt_id' => $debtId, 'recorded_by' => $actorId,
            'amount' => $amount, 'method' => 'cash', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function orderAndDebt(User $customer, string $status): array
    {
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'DH-SCHEDULE-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id,
            'status' => $status, 'total' => '100.00', 'paid' => '0.00', 'delivery_address' => 'Công trình',
            'fulfillment_method' => 'delivery', 'requires_contract' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $debtId = DB::table('debts')->insertGetId([
            'code' => 'CN-SCHEDULE-'.Str::upper(Str::random(6)), 'direction' => 'receivable',
            'customer_id' => $customer->id, 'order_id' => $orderId, 'amount' => '100.00',
            'paid' => '0.00', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$orderId, $debtId, $orderId];
    }
}
