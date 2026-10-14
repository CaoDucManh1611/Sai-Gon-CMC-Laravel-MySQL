<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginLockWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_locks_after_six_wrong_passwords_and_admin_can_unlock_it(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer', 'status' => 'active', 'username' => 'customer-lock',
            'password' => Hash::make('Correct123!'),
        ]);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['login' => 'customer-lock', 'password' => 'Wrong123!'])->assertSessionHasErrors('login');
            $this->assertNull($customer->fresh()->locked_until);
            $this->assertSame($attempt, $customer->fresh()->failed_logins);
        }
        $this->post('/login', ['login' => 'customer-lock', 'password' => 'Wrong123!'])
            ->assertSessionHasErrors(['login' => 'Tài khoản tạm khóa. Vui lòng liên hệ quản trị viên để mở khóa.']);
        $this->assertNotNull($customer->fresh()->locked_until);
        $this->post('/login', ['login' => 'customer-lock', 'password' => 'Correct123!'])->assertSessionHasErrors('login');
        $this->assertGuest();

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post('/management/users/'.$customer->id.'/unlock')->assertRedirect();
        $this->assertNull($customer->fresh()->locked_until);
        $this->assertSame(0, $customer->fresh()->failed_logins);
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['login' => 'customer-lock', 'password' => 'Correct123!'])->assertRedirect();
        $this->assertAuthenticatedAs($customer);
    }

    public function test_successful_login_resets_consecutive_wrong_attempts(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer', 'status' => 'active', 'username' => 'customer-reset',
            'password' => Hash::make('Correct123!'), 'failed_logins' => 4,
        ]);
        $this->post('/login', ['login' => 'customer-reset', 'password' => 'Correct123!'])->assertRedirect();
        $this->assertSame(0, $customer->fresh()->failed_logins);
    }
}
