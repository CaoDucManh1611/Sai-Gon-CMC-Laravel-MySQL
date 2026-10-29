<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierDebtConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_records_supplier_confirmation_privately_without_changing_payable_or_payment(): void
    {
        Storage::fake('local');
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $warehouse = User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        $supplierId = DB::table('suppliers')->insertGetId(['code' => 'NCC-XN', 'name' => 'Vật liệu Thành Công']);
        $debtId = DB::table('debts')->insertGetId([
            'code' => 'PT-XN-01', 'direction' => 'payable', 'supplier_id' => $supplierId,
            'amount' => '1000.00', 'paid' => '250.00', 'status' => 'open',
            'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(1),
        ]);
        $paymentId = DB::table('payments')->insertGetId([
            'code' => 'TT-XN-01', 'debt_id' => $debtId, 'recorded_by' => $accountant->id,
            'amount' => '250.00', 'method' => 'bank_transfer', 'paid_at' => now()->subDay(),
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($accountant)->post('/staff/supplier-debts/'.$debtId.'/confirmations', [
            'payment_id' => $paymentId,
            'contact_name' => 'Nguyễn Văn A',
            'confirmed_on' => now()->toDateString(),
            'statement' => 'Nhà cung cấp xác nhận đã nhận 250.000 đồng; số dư còn lại 750.000 đồng.',
            'attachment' => UploadedFile::fake()->create('xac-nhan.pdf', 80, 'application/pdf'),
        ])->assertRedirect();

        $confirmation = DB::table('supplier_debt_confirmations')->first();
        $this->assertNotNull($confirmation);
        $this->assertSame((string) $debtId, (string) $confirmation->debt_id);
        $this->assertSame((string) $paymentId, (string) $confirmation->payment_id);
        $this->assertSame($accountant->id, $confirmation->recorded_by);
        $this->assertSame('Nguyễn Văn A', $confirmation->contact_name);
        $this->assertSame('supplier-confirmations/'.basename($confirmation->file_path), $confirmation->file_path);
        Storage::disk('local')->assertExists($confirmation->file_path);
        $this->assertDatabaseHas('debts', ['id' => $debtId, 'amount' => '1000.00', 'paid' => '250.00', 'status' => 'open']);
        $this->assertDatabaseCount('payments', 1);

        $this->actingAs($warehouse)->get('/staff/supplier-debt-confirmations')->assertForbidden();
        $this->actingAs($warehouse)->get('/staff/supplier-debt-confirmations/'.$confirmation->id.'/attachment')->assertForbidden();
        $this->actingAs($warehouse)->post('/staff/supplier-debts/'.$debtId.'/confirmations', [
            'contact_name' => 'Kho', 'confirmed_on' => now()->toDateString(), 'statement' => 'Ghi thử',
        ])->assertForbidden();

        $this->actingAs($accountant)->get('/staff/supplier-debt-confirmations')->assertOk()
            ->assertSee('Vật liệu Thành Công')->assertSee('xác nhận được kế toán ghi nhận');
        $this->actingAs($accountant)->get('/staff/supplier-debt-confirmations/'.$confirmation->id.'/attachment')->assertOk();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get('/staff/supplier-debt-confirmations')->assertOk();
        $this->actingAs($admin)->get('/staff/supplier-debt-confirmations/'.$confirmation->id.'/attachment')->assertOk();
    }

    public function test_supplier_confirmation_requires_a_supported_private_attachment_and_matching_payment(): void
    {
        Storage::fake('local');
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $supplierId = DB::table('suppliers')->insertGetId(['code' => 'NCC-XN-2', 'name' => 'Nhà cung cấp khác']);
        $otherSupplierId = DB::table('suppliers')->insertGetId(['code' => 'NCC-XN-3', 'name' => 'Không liên quan']);
        $debtId = DB::table('debts')->insertGetId([
            'code' => 'PT-XN-02', 'direction' => 'payable', 'supplier_id' => $supplierId,
            'amount' => '50.00', 'paid' => '0.00', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDebtId = DB::table('debts')->insertGetId([
            'code' => 'PT-XN-03', 'direction' => 'payable', 'supplier_id' => $otherSupplierId,
            'amount' => '50.00', 'paid' => '0.00', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherPaymentId = DB::table('payments')->insertGetId([
            'code' => 'TT-XN-02', 'debt_id' => $otherDebtId, 'recorded_by' => $accountant->id,
            'amount' => '10.00', 'method' => 'cash', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($accountant)->post('/staff/supplier-debts/'.$debtId.'/confirmations', [
            'contact_name' => 'Người liên hệ', 'confirmed_on' => now()->toDateString(),
            'statement' => 'Xác nhận', 'payment_id' => $otherPaymentId,
            'attachment' => UploadedFile::fake()->create('bang-ke.exe', 30, 'application/x-msdownload'),
        ])->assertSessionHasErrors('attachment');
        $this->actingAs($accountant)->post('/staff/supplier-debts/'.$debtId.'/confirmations', [
            'contact_name' => 'Người liên hệ', 'confirmed_on' => now()->toDateString(),
            'statement' => 'Xác nhận', 'payment_id' => $otherPaymentId,
            'attachment' => UploadedFile::fake()->create('bang-ke.pdf', 30, 'application/pdf'),
        ])->assertSessionHasErrors('payment_id');
        $this->assertDatabaseCount('supplier_debt_confirmations', 0);
        $this->assertDatabaseHas('debts', ['id' => $debtId, 'amount' => '50.00', 'paid' => '0.00']);
    }

    public function test_supplier_payables_are_paginated_so_older_confirmations_remain_reachable(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $supplierId = DB::table('suppliers')->insertGetId(['code' => 'NCC-PAGE', 'name' => 'NCC phân trang']);
        for ($number = 1; $number <= 21; $number++) {
            DB::table('debts')->insert([
                'code' => sprintf('PT-PAGE-%02d', $number), 'direction' => 'payable', 'supplier_id' => $supplierId,
                'amount' => '50.00', 'paid' => '0.00', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($accountant)->get('/staff/supplier-debt-confirmations?page=2')->assertOk()
            ->assertSee('PT-PAGE-01')->assertDontSee('PT-PAGE-21');
    }
}
