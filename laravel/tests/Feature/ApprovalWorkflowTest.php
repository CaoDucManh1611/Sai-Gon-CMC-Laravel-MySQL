<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CatalogWorkflow;
use App\Services\CommerceWorkflow;
use App\Services\FulfillmentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_proposer_cannot_approve_their_own_quote(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $salesperson = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $productId = DB::table('products')->insertGetId([
            'code' => 'APPROVAL-QUOTE-SELF', 'name' => 'Gạch', 'unit' => 'viên', 'price' => '100.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $workflow = app(CommerceWorkflow::class);
        $quoteId = $workflow->requestQuote($customer->id, [$productId => 1], 'Công trình');
        $itemId = DB::table('quote_items')->where('quote_id', $quoteId)->value('id');
        $workflow->sendQuote($quoteId, $salesperson->id, [$itemId => '90.00'], now()->addWeek()->toDateString());

        $selfApprovalRejected = false;
        try {
            $workflow->reviewQuoteDiscount($quoteId, $salesperson->id, true);
        } catch (ValidationException) {
            $selfApprovalRejected = true;
        }

        $this->assertTrue($selfApprovalRejected, 'Người đề xuất chiết khấu không được tự duyệt dù có quyền quản lý.');
        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'status' => 'pending_discount_approval']);

        $reviewer = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $missingReasonRejected = false;
        try {
            $workflow->reviewQuoteDiscount($quoteId, $reviewer->id, false);
        } catch (ValidationException) {
            $missingReasonRejected = true;
        }
        $this->assertTrue($missingReasonRejected);
        $this->assertDatabaseCount('approval_events', 0);

        $workflow->reviewQuoteDiscount($quoteId, $reviewer->id, false, 'Chiết khấu vượt mức được duyệt.');
        $firstEvent = DB::table('approval_events')->where('subject_type', 'quote_discount')->where('subject_id', $quoteId)->first();
        $this->assertSame('rejected', $firstEvent->decision);
        $this->assertSame('pending_discount_approval', $firstEvent->status_from);
        $this->assertSame('draft', $firstEvent->status_to);
        $this->assertSame('Chiết khấu vượt mức được duyệt.', $firstEvent->reason);
        $this->assertSame(hash('sha256', $firstEvent->content_snapshot), $firstEvent->content_hash);

        $itemId = DB::table('quote_items')->where('quote_id', $quoteId)->value('id');
        $workflow->sendQuote($quoteId, $salesperson->id, [$itemId => '90.00'], now()->addWeek()->toDateString());
        $workflow->reviewQuoteDiscount($quoteId, $reviewer->id, true);
        $this->assertDatabaseCount('approval_events', 2);
        $this->assertDatabaseHas('approval_events', [
            'subject_type' => 'quote_discount', 'subject_id' => $quoteId,
            'decision' => 'approved', 'status_from' => 'pending_discount_approval', 'status_to' => 'sent',
        ]);
    }

    public function test_contract_review_is_independent_requires_revision_reason_and_keeps_each_decision(): void
    {
        $creator = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $reviewer = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $quoteId = DB::table('quotes')->insertGetId([
            'code' => 'BG-APPROVAL-CONTRACT', 'customer_id' => $customer->id, 'status' => 'accepted',
            'delivery_address' => 'Công trình', 'total' => '100.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'code' => 'DH-APPROVAL-CONTRACT', 'quote_id' => $quoteId, 'customer_id' => $customer->id,
            'status' => 'awaiting_contract', 'total' => '100.00', 'paid' => '0.00',
            'delivery_address' => 'Công trình', 'fulfillment_method' => 'delivery', 'requires_contract' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'code' => 'PRODUCT-APPROVAL-CONTRACT', 'name' => 'Gạch tại thời điểm duyệt',
            'specification' => 'Quy cách #42', 'unit' => 'kg', 'price' => '10.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'quantity' => '12.000',
            'unit_price' => '10.00', 'line_total' => '120.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'code' => 'HD-APPROVAL-CONTRACT', 'quote_id' => $quoteId, 'order_id' => $orderId, 'created_by' => $creator->id,
            'status' => 'draft', 'total' => '100.00', 'deposit_required' => '30.00',
            'payment_terms' => 'Đợt 1 trước xuất', 'delivery_terms' => 'Giao tại công trình',
            'payment_schedule_draft' => json_encode([['sequence' => 1, 'amount' => '100.00']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $workflow = app(CommerceWorkflow::class);
        $workflow->submitContract($contractId, $creator->id);

        $selfApprovalRejected = false;
        try {
            $workflow->reviewContract($contractId, $creator->id, true);
        } catch (ValidationException) {
            $selfApprovalRejected = true;
        }
        $this->assertTrue($selfApprovalRejected);

        $missingReasonRejected = false;
        try {
            $workflow->reviewContract($contractId, $reviewer->id, false);
        } catch (ValidationException) {
            $missingReasonRejected = true;
        }
        $this->assertTrue($missingReasonRejected);
        $this->assertSame(0, DB::table('approval_events')->where('subject_type', 'contract')->where('subject_id', $contractId)->count());

        $workflow->reviewContract($contractId, $reviewer->id, false, 'Cần đổi mốc thanh toán.');
        $firstEvent = DB::table('approval_events')->where('subject_type', 'contract')->where('subject_id', $contractId)->first();
        $this->assertSame('changes_requested', $firstEvent->decision);
        $this->assertSame('Cần đổi mốc thanh toán.', $firstEvent->reason);
        $this->assertStringContainsString('Đợt 1 trước xuất', $firstEvent->content_snapshot);
        $contentSnapshot = json_decode($firstEvent->content_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Gạch tại thời điểm duyệt', $contentSnapshot['order_items'][0]['product_name']);
        $this->assertSame('Quy cách #42', $contentSnapshot['order_items'][0]['product_specification']);
        $this->assertSame('kg', $contentSnapshot['order_items'][0]['product_unit']);
        $this->assertSame(0, bccomp((string) $contentSnapshot['order_items'][0]['quantity'], '12.000', 3));
        $this->assertSame(0, bccomp((string) $contentSnapshot['order_items'][0]['unit_price'], '10.00', 2));
        $this->assertSame(0, bccomp((string) $contentSnapshot['order_items'][0]['line_total'], '120.00', 2));

        $workflow->submitContract($contractId, $creator->id);
        $workflow->reviewContract($contractId, $reviewer->id, true);
        $this->assertSame(2, DB::table('approval_events')->where('subject_type', 'contract')->where('subject_id', $contractId)->count());
        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'status' => 'approved', 'approved_by' => $reviewer->id]);
        $this->actingAs($reviewer)->get('/staff')->assertOk()
            ->assertSee('Lịch sử xử lý gần đây')->assertSee('Hợp đồng #'.$contractId);
    }

    public function test_contract_reviewer_cannot_be_the_current_version_editor_or_submitter(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $creator = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $editor = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $submitter = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $workflow = app(CommerceWorkflow::class);

        $createPendingContract = function (string $suffix, int $lastEditorId, int $lastSubmitterId) use ($customer, $creator, $workflow): int {
            $quoteId = DB::table('quotes')->insertGetId([
                'code' => 'BG-ADMIN-EDIT-'.$suffix, 'customer_id' => $customer->id, 'status' => 'accepted',
                'delivery_address' => 'Công trình', 'total' => '100.00', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $contractId = DB::table('contracts')->insertGetId([
                'code' => 'HD-ADMIN-EDIT-'.$suffix, 'quote_id' => $quoteId, 'created_by' => $creator->id,
                'last_edited_by' => $lastEditorId, 'status' => 'draft', 'total' => '100.00', 'deposit_required' => '0.00',
                'payment_terms' => 'Sau bàn giao', 'delivery_terms' => 'Tại công trình',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $workflow->submitContract($contractId, $lastSubmitterId);

            return $contractId;
        };

        $editedByAdmin = $createPendingContract('EDITOR', $admin->id, $submitter->id);
        try {
            $workflow->reviewContract($editedByAdmin, $admin->id, true);
            $this->fail('Admin vừa sửa phiên bản hợp đồng không được tự duyệt.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('approval_events')->where('subject_type', 'contract')->where('subject_id', $editedByAdmin)->count());
        }

        $submittedByAdmin = $createPendingContract('SUBMITTER', $editor->id, $admin->id);
        try {
            $workflow->reviewContract($submittedByAdmin, $admin->id, true);
            $this->fail('Admin vừa trình phiên bản hợp đồng không được tự duyệt.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('approval_events')->where('subject_type', 'contract')->where('subject_id', $submittedByAdmin)->count());
        }
    }

    public function test_catalog_purchase_and_stocktake_reviews_reject_self_approval_and_record_snapshots(): void
    {
        $creator = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $reviewer = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $catalog = app(CatalogWorkflow::class);
        $proposalId = $catalog->propose('category_create', null, [
            'code' => 'CAT-APPROVAL', 'name' => 'Vật liệu mới', 'active' => true,
        ], $creator->id);

        $catalogSelfApprovalRejected = false;
        try {
            $catalog->review($proposalId, $creator->id, true);
        } catch (ValidationException) {
            $catalogSelfApprovalRejected = true;
        }
        $this->assertTrue($catalogSelfApprovalRejected);
        $catalog->review($proposalId, $reviewer->id, true);
        $this->assertDatabaseHas('approval_events', [
            'subject_type' => 'catalog_change', 'subject_id' => $proposalId,
            'status_from' => 'pending', 'status_to' => 'approved', 'actor_id' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('categories', ['code' => 'CAT-APPROVAL']);

        $supplierId = DB::table('suppliers')->insertGetId(['code' => 'SUP-APPROVAL', 'name' => 'Nhà cung cấp']);
        $warehouseId = DB::table('warehouses')->insertGetId(['code' => 'WH-APPROVAL', 'name' => 'Kho']);
        $productId = DB::table('products')->insertGetId([
            'code' => 'PR-APPROVAL', 'name' => 'Gạch', 'unit' => 'viên', 'price' => '10.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseId = DB::table('purchase_orders')->insertGetId([
            'code' => 'PO-APPROVAL', 'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId,
            'requested_by' => $creator->id, 'status' => 'pending', 'total' => '10.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_items')->insert([
            'purchase_order_id' => $purchaseId, 'product_id' => $productId,
            'quantity' => 1, 'unit_price' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fulfillment = app(FulfillmentWorkflow::class);
        $purchaseSelfApprovalRejected = false;
        try {
            $fulfillment->approvePurchaseOrder($purchaseId, $creator->id);
        } catch (ValidationException) {
            $purchaseSelfApprovalRejected = true;
        }
        $this->assertTrue($purchaseSelfApprovalRejected);
        $fulfillment->approvePurchaseOrder($purchaseId, $reviewer->id);
        $this->assertDatabaseHas('approval_events', [
            'subject_type' => 'purchase_order', 'subject_id' => $purchaseId,
            'status_from' => 'pending', 'status_to' => 'approved', 'actor_id' => $reviewer->id,
        ]);

        DB::table('inventory')->insert([
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stocktakeId = $fulfillment->proposeStocktake($warehouseId, $productId, $creator->id, '12', 'Đếm lại tồn');
        $stocktakeSelfApprovalRejected = false;
        try {
            $fulfillment->reviewStocktake($stocktakeId, $creator->id, true);
        } catch (ValidationException) {
            $stocktakeSelfApprovalRejected = true;
        }
        $this->assertTrue($stocktakeSelfApprovalRejected);
        $this->assertDatabaseHas('inventory', ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 10]);
        $fulfillment->reviewStocktake($stocktakeId, $reviewer->id, true);
        $this->assertDatabaseHas('approval_events', [
            'subject_type' => 'stocktake', 'subject_id' => $stocktakeId,
            'status_from' => 'pending', 'status_to' => 'approved', 'actor_id' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('inventory', ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 12]);
    }

    public function test_report_creator_cannot_review_their_own_report_and_rejection_requires_reason(): void
    {
        $creator = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $reviewer = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $reportId = DB::table('reports')->insertGetId([
            'code' => 'RP-APPROVAL-'.uniqid(), 'type' => 'sales', 'created_by' => $creator->id,
            'from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'snapshot' => json_encode(['sales' => []]),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($creator)->post('/staff/reports/'.$reportId.'/review', ['decision' => 'approved'])
            ->assertSessionHasErrors('approval');
        $this->assertDatabaseHas('reports', ['id' => $reportId, 'status' => 'pending']);
        $this->actingAs($reviewer)->post('/staff/reports/'.$reportId.'/review', ['decision' => 'rejected'])
            ->assertSessionHasErrors('note');
        $this->assertDatabaseCount('approval_events', 0);
        $this->actingAs($reviewer)->post('/staff/reports/'.$reportId.'/review', [
            'decision' => 'rejected', 'note' => 'Báo cáo cần đối soát lại số liệu.',
        ])->assertRedirect();

        $this->assertDatabaseHas('reports', ['id' => $reportId, 'status' => 'rejected', 'approved_by' => $reviewer->id]);
        $this->assertDatabaseHas('approval_events', [
            'subject_type' => 'report', 'subject_id' => $reportId,
            'decision' => 'rejected', 'status_from' => 'pending', 'status_to' => 'rejected',
            'actor_id' => $reviewer->id, 'reason' => 'Báo cáo cần đối soát lại số liệu.',
        ]);
    }

    public function test_discount_quote_submission_reports_that_it_is_waiting_for_approval(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $salesperson = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $productId = DB::table('products')->insertGetId([
            'code' => 'APPROVAL-QUOTE-MESSAGE', 'name' => 'Gạch', 'unit' => 'viên', 'price' => '100.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $quoteId = app(CommerceWorkflow::class)->requestQuote($customer->id, [$productId => 1], 'Công trình');
        $itemId = DB::table('quote_items')->where('quote_id', $quoteId)->value('id');

        $this->actingAs($salesperson)->post('/staff/quotes/'.$quoteId.'/send', [
            'valid_until' => now()->addWeek()->toDateString(), 'prices' => [$itemId => '90.00'],
        ])->assertRedirect()->assertSessionHas('success', 'Đã trình duyệt chiết khấu.');
        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'status' => 'pending_discount_approval']);
    }

    public function test_managers_can_paginate_complete_approval_history_and_sales_cannot_access_it(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        for ($index = 1; $index <= 25; $index++) {
            $snapshot = json_encode(['version' => $index], JSON_THROW_ON_ERROR);
            DB::table('approval_events')->insert([
                'subject_type' => 'report', 'subject_id' => $index, 'decision' => 'approved',
                'status_from' => 'pending', 'status_to' => 'approved', 'actor_id' => $manager->id,
                'content_hash' => hash('sha256', $snapshot), 'content_snapshot' => $snapshot,
                'decided_at' => now()->subDays($index), 'created_at' => now()->subDays($index),
                'updated_at' => now()->subDays($index),
            ]);
        }

        $this->actingAs($manager)->get('/staff/approval-history')->assertOk()
            ->assertSee('Lịch sử phê duyệt')->assertSee('page=2');
        $this->actingAs($manager)->get('/staff/approval-history?page=2')->assertOk()
            ->assertSee('Báo cáo #25');
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $this->actingAs($sales)->get('/staff/approval-history')->assertForbidden();
    }
}
