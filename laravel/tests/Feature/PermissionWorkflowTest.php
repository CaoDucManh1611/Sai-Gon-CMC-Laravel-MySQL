<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_are_backed_by_permissions_and_admin_gets_the_full_matrix(): void
    {
        $warehouse = User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->assertTrue($warehouse->hasPermission('inventory.issue'));
        $this->assertFalse($warehouse->hasPermission('payments.record'));
        $this->assertTrue($accountant->hasPermission('payments.record'));
        $this->assertFalse($accountant->hasPermission('users.manage'));
        $this->assertTrue($admin->hasPermission('users.manage'));
        $this->assertTrue($admin->hasPermission('payments.record'));
        $this->assertSame($admin->role_id, DB::table('roles')->where('code', 'admin')->value('id'));
        $this->actingAs($admin)->get('/management')->assertOk()
            ->assertSee('Ma trận vai trò và quyền')->assertSee('customer.portal')->assertSee('users.manage');
    }

    public function test_permissions_are_enforced_on_direct_requests_and_role_changes_sync_the_foreign_key(): void
    {
        $warehouse = User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($warehouse)->post('/staff/debts/1/payments', [
            'amount' => '100.00', 'method' => 'cash',
        ])->assertForbidden();

        $this->actingAs($admin)->put('/management/users/'.$warehouse->id, [
            'role' => 'accounting', 'status' => 'active',
        ])->assertRedirect();

        $warehouse->refresh();
        $this->assertSame('accounting', $warehouse->role);
        $this->assertSame(DB::table('roles')->where('code', 'accounting')->value('id'), $warehouse->role_id);
    }

    public function test_customer_with_business_history_cannot_be_converted_to_an_internal_account(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        DB::table('customers')->insert([
            'user_id' => $customer->id,
            'code' => 'KH-PERM-'.str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('quotes')->insert([
            'code' => 'BG-PERM-'.str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'status' => 'requested',
            'delivery_address' => 'Địa chỉ thử',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->put('/management/users/'.$customer->id, [
            'role' => 'sales', 'status' => 'active',
        ])->assertSessionHasErrors('workflow');

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'role' => 'customer']);
    }

    public function test_staff_account_with_only_quote_salesperson_history_cannot_be_converted_to_customer(): void
    {
        $salesperson = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        DB::table('quotes')->insert([
            'code' => 'BG-ACTOR-'.str_pad((string) $salesperson->id, 6, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'salesperson_id' => $salesperson->id,
            'status' => 'requested',
            'delivery_address' => 'Địa chỉ thử',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->put('/management/users/'.$salesperson->id, [
            'role' => 'customer', 'status' => 'active',
        ])->assertSessionHasErrors('workflow');

        $this->assertDatabaseHas('users', ['id' => $salesperson->id, 'role' => 'sales']);
    }

    public function test_dashboard_hides_financial_data_when_finance_permission_is_removed(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $financePermissionId = DB::table('permissions')->where('code', 'finance.view')->value('id');
        DB::table('role_permissions')->where('role_id', $accountant->role_id)
            ->where('permission_id', $financePermissionId)->delete();
        DB::table('debts')->insert([
            'code' => 'DEBT-PRIVATE-9001', 'direction' => 'receivable',
            'customer_id' => $accountant->id, 'amount' => '1234567.89', 'paid' => '0',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($accountant)->get('/staff')->assertOk()
            ->assertDontSee('DEBT-PRIVATE-9001')->assertDontSee('1.234.567');
        $this->get('/staff/work/finance')->assertForbidden();
    }

    public function test_dashboard_fallback_handles_removed_overview_permissions(): void
    {
        $salesperson = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $quotesPermissionId = DB::table('permissions')->where('code', 'quotes.view')->value('id');
        DB::table('role_permissions')->where('role_id', $salesperson->role_id)
            ->where('permission_id', $quotesPermissionId)->delete();

        $this->actingAs($salesperson)->get('/staff')->assertOk()
            ->assertSee('Đơn hàng')->assertDontSee('Báo giá');
    }

    public function test_permission_specific_forms_match_the_routes_that_accept_them(): void
    {
        $warehouse = User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        $warehousePermissionId = DB::table('permissions')->where('code', 'warehouses.manage')->value('id');
        DB::table('role_permissions')->where('role_id', $warehouse->role_id)
            ->where('permission_id', $warehousePermissionId)->delete();

        $this->actingAs($warehouse)->get('/management')->assertOk()
            ->assertSee(route('management.suppliers.save'))
            ->assertDontSee(route('management.warehouses.save'));

        $salesperson = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $createReportPermissionId = DB::table('permissions')->where('code', 'reports.create')->value('id');
        DB::table('role_permissions')->where('role_id', $salesperson->role_id)
            ->where('permission_id', $createReportPermissionId)->delete();

        $this->actingAs($salesperson)->get('/staff/reports')->assertOk()
            ->assertDontSee('Loại báo cáo');
        $this->post(route('staff.reports.create'), [])->assertForbidden();
    }

    public function test_last_active_admin_cannot_be_demoted_or_disabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        DB::table('role_permissions')->insert([
            'role_id' => $manager->role_id,
            'permission_id' => DB::table('permissions')->where('code', 'users.manage')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($manager)->put('/management/users/'.$admin->id, [
            'role' => 'manager', 'status' => 'active',
        ])->assertSessionHasErrors('workflow');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
    }

    public function test_permission_seeding_is_repeatable_and_keeps_custom_assignments(): void
    {
        $salesRoleId = DB::table('roles')->where('code', 'sales')->value('id');
        $customPermissionId = DB::table('permissions')->insertGetId([
            'code' => 'custom.analytics', 'name' => 'Quyền phân tích riêng',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert([
            'role_id' => $salesRoleId, 'permission_id' => $customPermissionId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->seed(RolePermissionSeeder::class);

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $salesRoleId, 'permission_id' => $customPermissionId,
        ]);
        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $salesRoleId)
            ->where('permission_id', $customPermissionId)->count());
    }

    public function test_accounts_without_the_required_permission_cannot_call_management_routes(): void
    {
        $accountant = User::factory()->create(['role' => 'accounting', 'status' => 'active']);

        $this->actingAs($accountant)->post('/management/users', [
            'name' => 'Tài khoản thử', 'username' => 'forbidden_user',
            'role' => 'warehouse', 'password' => 'TestPass2026!',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'forbidden_user']);
    }
}
