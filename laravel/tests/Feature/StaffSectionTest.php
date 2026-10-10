<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_navigation_matches_account_role(): void
    {
        $staff = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($staff)->get('/staff')->assertOk()
            ->assertSee('Bảng làm việc')->assertSee('staff-overview-grid')->assertDontSee('Báo giá của tôi');
        $this->actingAs($staff)->get('/staff/work/finance')->assertOk()
            ->assertSee('staff-workspace-scroll')->assertSee('Danh sách và thao tác Tài chính &amp; công nợ', false);

        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $this->actingAs($customer)->get('/')->assertOk()
            ->assertSee('Báo giá của tôi')->assertDontSee('href="'.route('staff.dashboard').'"', false);
    }

    public function test_every_staff_role_can_open_only_its_work_sections(): void
    {
        $sections = [
            'quotes' => ['sales', 'manager', 'admin'],
            'contracts' => ['sales', 'manager', 'admin'],
            'finance' => ['accounting', 'manager', 'admin'],
            'purchases' => ['warehouse', 'manager', 'admin'],
            'orders' => ['warehouse', 'delivery', 'accounting', 'manager', 'admin'],
            'returns' => ['warehouse', 'accounting', 'manager', 'admin'],
            'inventory' => ['warehouse', 'manager', 'admin'],
        ];
        foreach (['sales', 'warehouse', 'accounting', 'manager', 'admin', 'delivery'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get('/staff')->assertOk();
            foreach ($sections as $section => $allowedRoles) {
                $response = $this->actingAs($user)->get('/staff/work/'.$section);
                in_array($role, $allowedRoles, true) ? $response->assertOk() : $response->assertForbidden();
            }
        }
    }

    public function test_only_admin_can_manage_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $fields = ['name' => 'Nhân viên thử', 'username' => 'qa_new_staff', 'role' => 'warehouse', 'password' => 'TestPass2026!'];

        $this->actingAs($sales)->post('/management/users', $fields)->assertForbidden();
        $this->actingAs($admin)->post('/management/users', $fields)->assertRedirect();
        $created = User::where('username', 'qa_new_staff')->firstOrFail();
        $this->assertTrue(Hash::check('TestPass2026!', $created->password));
        $this->actingAs($admin)->put('/management/users/'.$created->id, ['role' => 'warehouse', 'status' => 'disabled'])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $created->id, 'status' => 'disabled']);
        $this->actingAs($admin)->put('/management/users/'.$admin->id, ['role' => 'sales', 'status' => 'active'])->assertStatus(422);
    }

    public function test_staff_sees_only_work_pages_allowed_for_their_role(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $this->actingAs($sales)->get('/staff/work/quotes')->assertOk()->assertSee('Báo giá');
        $this->actingAs($sales)->get('/staff/work/finance')->assertForbidden();

        $delivery = User::factory()->create(['role' => 'delivery', 'status' => 'active']);
        $this->actingAs($delivery)->get('/staff/work/orders')->assertOk()->assertSee('Xuất kho & giao nhận');
        $this->actingAs($delivery)->get('/staff/work/inventory')->assertForbidden();
        $this->actingAs($delivery)->get('/staff/work/unknown')->assertNotFound();

        $warehouse = User::factory()->create(['role' => 'warehouse', 'status' => 'active']);
        $this->actingAs($warehouse)->get('/staff/work/purchases')->assertOk()->assertSee('Hàng giao thiếu hoặc hỏng');
        $this->actingAs($warehouse)->get('/staff/work/orders')->assertOk()->assertSee('Lộ trình nhiều đơn');

        $accounting = User::factory()->create(['role' => 'accounting', 'status' => 'active']);
        $this->actingAs($accounting)->get('/staff/work/finance')->assertOk()->assertSee('Lịch quyết toán công nợ');

        $this->actingAs($sales)->post('/staff/debts/1/schedule')->assertForbidden();
        $this->actingAs($sales)->post('/staff/supplier-returns/1/settle')->assertForbidden();
        $this->actingAs($sales)->post('/staff/customer-credits/1/use')->assertForbidden();
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $this->actingAs($manager)->get('/staff/work/finance')->assertOk()->assertSee('Tiền khách được hoàn hoặc cấn trừ');
        $this->actingAs($manager)->post('/staff/customer-credits/1/use')->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get('/staff/work/finance')->assertOk()->assertSee('Tiền khách được hoàn hoặc cấn trừ');
        $this->actingAs($admin)->post('/staff/customer-credits/1/use')->assertSessionHasErrors(['type', 'amount']);
        $this->actingAs($sales)->post('/staff/purchases/1/incidents')->assertForbidden();
        $this->actingAs($delivery)->post('/staff/contracts/1/cancel')->assertForbidden();
        $this->actingAs($delivery)->post('/staff/delivery-runs')->assertForbidden();
    }
}
