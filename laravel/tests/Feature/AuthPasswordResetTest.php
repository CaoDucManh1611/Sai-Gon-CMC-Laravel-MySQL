<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_request_password_reset_with_login_email(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'role' => 'customer', 'status' => 'active', 'username' => 'newcustomer@example.com',
            'phone' => null, 'email' => 'newcustomer@example.com',
        ]);

        $this->post('/forgot-password', ['login' => 'NEWCUSTOMER@EXAMPLE.COM'])
            ->assertRedirect()->assertSessionHas('status');
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_customer_can_reset_password_from_a_link_sent_to_saved_email(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'role' => 'customer', 'status' => 'active', 'username' => '0901234567',
            'phone' => '0901234567', 'email' => 'customer@example.com',
            'failed_logins' => 6, 'locked_until' => now(),
        ]);

        $this->get('/login')->assertOk()->assertSee('Quên mật khẩu?')->assertSee(route('password.request'));
        $this->post('/forgot-password', ['login' => '+84 901 234 567'])->assertRedirect()->assertSessionHas('status');
        Notification::assertSentToTimes($user, ResetPassword::class, 1);

        $token = Notification::sent($user, ResetPassword::class)->first()->token;
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()->assertSee('Email nhận liên kết');
        $this->post('/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123', $user->password));
        $this->assertSame(0, $user->failed_logins);
        $this->assertNull($user->locked_until);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_password_recovery_does_not_disclose_accounts_without_email(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'role' => 'customer', 'username' => '0901234568', 'phone' => '0901234568',
            'email' => null,
        ]);

        $this->post('/forgot-password', ['login' => $user->phone])
            ->assertRedirect()->assertSessionHas('status');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_customer_can_save_an_optional_recovery_email_in_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'customer', 'status' => 'active', 'phone' => '0901234569', 'email' => null,
        ]);

        $this->actingAs($user)->put('/my/profile', [
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => 'recovery@example.com',
            'address' => 'Địa chỉ liên hệ',
        ])->assertRedirect();

        $this->assertSame('recovery@example.com', $user->fresh()->email);
    }
}
