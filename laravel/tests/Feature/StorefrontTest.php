<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CommerceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_form_uses_email_and_otp_fields_without_phone(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('Email đăng nhập')
            ->assertSee('Gửi mã OTP')
            ->assertSee('name="otp"', false)
            ->assertDontSee('Xác nhận OTP')
            ->assertDontSee('verifyRegistrationOtp')
            ->assertDontSee('name="phone"', false);
    }

    public function test_customer_registers_with_correct_otp_and_logs_back_in(): void
    {
        $this->withSession(['registration_otp' => [
            'email' => 'customer@example.com', 'hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5)->timestamp, 'attempts' => 0,
        ]]);

        $this->post('/register', [
            'name' => 'Khách Hàng Thử', 'email' => 'customer@example.com', 'address' => 'Địa chỉ thử',
            'otp' => '123456', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123',
        ])->assertRedirect('/products');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['phone' => null, 'email' => 'customer@example.com', 'username' => 'customer@example.com']);
        $this->assertNotNull(auth()->user()->email_verified_at);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->post('/login', ['login' => 'Khách Hàng Thử', 'password' => 'StrongPass123'])
            ->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'CUSTOMER@EXAMPLE.COM', 'password' => 'StrongPass123'])
            ->assertRedirect('/products');
        $this->assertAuthenticated();
    }

    public function test_registration_rejects_missing_or_wrong_otp(): void
    {
        $data = ['name' => 'Nguyễn Văn B', 'email' => 'guest@example.com', 'address' => 'Công trình B',
            'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123'];
        $this->post('/register', $data)->assertSessionHasErrors('otp');
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);

        $this->withSession(['registration_otp' => [
            'email' => 'guest@example.com', 'hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5)->timestamp, 'attempts' => 0,
        ]]);
        $this->post('/register', [...$data, 'otp' => '999999'])
            ->assertSessionHasErrors(['otp' => 'Mã OTP không hợp lệ. Vui lòng kiểm tra và thử lại.']);
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
        $this->assertSame(1, session('registration_otp.attempts'));

        $this->post('/register', [...$data, 'otp' => '12345'])->assertSessionHasErrors('otp');
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
    }

    public function test_registration_rejects_expired_otp(): void
    {
        $this->withSession(['registration_otp' => [
            'email' => 'guest@example.com', 'hash' => Hash::make('123456'),
            'expires_at' => now()->subSecond()->timestamp, 'attempts' => 0,
        ]])->post('/register', [
            'name' => 'Nguyễn Văn B', 'email' => 'guest@example.com', 'otp' => '123456',
            'address' => 'Công trình B', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123',
        ])->assertSessionHasErrors('otp');
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
    }

    public function test_registration_can_send_otp_and_limits_repeat_requests(): void
    {
        $sentCode = null;
        Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $message) use (&$sentCode): void {
            preg_match('/\b[0-9]{6}\b/', $message, $matches);
            $sentCode = $matches[0] ?? null;
        });
        $this->postJson('/register/send-otp', ['email' => 'guest@example.com'])->assertOk();
        $this->assertNotNull($sentCode);
        $this->assertSame('guest@example.com', session('registration_otp.email'));
        $this->assertTrue(Hash::check($sentCode, session('registration_otp.hash')));
        $this->postJson('/register/send-otp', ['email' => 'guest@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_invalid_email_before_sending_otp(): void
    {
        Mail::fake();
        $this->postJson('/register/send-otp', ['email' => 'not-an-email'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertNothingSent();
    }

    public function test_registration_rejects_otp_for_a_different_email(): void
    {
        $this->withSession(['registration_otp' => [
            'email' => 'first@example.com', 'hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5)->timestamp, 'attempts' => 0,
        ]])->post('/register', [
            'name' => 'Nguyễn Văn C', 'email' => 'second@example.com', 'otp' => '123456',
            'address' => 'Công trình C', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123',
        ])->assertSessionHasErrors('otp');
        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_registration_requires_new_code_after_five_wrong_attempts(): void
    {
        $data = ['name' => 'Nguyễn Văn B', 'email' => 'guest@example.com', 'otp' => '999999',
            'address' => 'Công trình B', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123'];
        $this->withSession(['registration_otp' => [
            'email' => 'guest@example.com', 'hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5)->timestamp, 'attempts' => 0,
        ]]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/register', $data)->assertSessionHasErrors('otp');
        }

        $this->assertNull(session('registration_otp'));
        $this->post('/register', [...$data, 'otp' => '123456'])->assertSessionHasErrors('otp');
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
    }

    public function test_storefront_displays_styled_success_notice_and_select_enhancement(): void
    {
        $this->withSession(['success' => 'Đăng ký thành công.'])
            ->get('/products')
            ->assertOk()
            ->assertSee('site-notice-success')
            ->assertSee('Đăng ký thành công.')
            ->assertSee('store/components.js');
    }

    public function test_customer_can_register_and_send_cart_as_quote_request(): void
    {
        $product = DB::table('products')->insertGetId(['code' => 'G1', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 100]);
        $this->withSession(['registration_otp' => [
            'email' => 'buyer@example.com', 'hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5)->timestamp, 'attempts' => 0,
        ]]);
        $response = $this->post('/register', [
            'name' => 'Nguyễn Văn A', 'email' => 'buyer@example.com', 'address' => 'Công trình A',
            'otp' => '123456', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123',
        ]);
        $response->assertRedirect('/products');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('customers', ['user_id' => auth()->id()]);
        $this->post('/cart/add', ['product_id' => $product, 'quantity' => 3])
            ->assertRedirect()
            ->assertSessionHas('cart_added.name', 'Gạch');
        $this->get('/products/'.$product)->assertOk()->assertSee('Đã thêm vào giỏ hàng')->assertSee('Trong giỏ: 3 viên')->assertSee('Xem giỏ báo giá');
        $this->post('/quotes', ['delivery_address' => 'Công trình A'])->assertRedirect('/my/quotes');
        $this->assertDatabaseHas('quotes', ['customer_id' => auth()->id(), 'status' => 'requested', 'total' => 300]);
        $this->assertDatabaseHas('quote_items', ['product_id' => $product, 'quantity' => 3, 'unit_price' => 100]);
    }

    public function test_customer_cannot_enter_staff_workflow(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get('/staff')->assertForbidden();
    }

    public function test_product_detail_quantity_replaces_old_cart_quantity(): void
    {
        $product = DB::table('products')->insertGetId(['code' => 'G-SET', 'name' => 'Gạch tuynel', 'unit' => 'viên', 'price' => 1250]);
        $this->withSession(['cart' => [$product => 4]])->get('/products/'.$product)
            ->assertOk()->assertSee('value="4"', false)->assertSee('data-product-stepper')->assertSee('Đặt hàng');
        $this->post('/cart/add', ['product_id' => $product, 'quantity' => 2, 'replace' => 1])
            ->assertRedirect()->assertSessionHas('cart_added.quantity', 2);
        $this->post('/cart/add', ['product_id' => $product, 'quantity' => 2, 'replace' => 1, 'next' => 'cart'])
            ->assertRedirect('/cart');
        $this->assertEquals(2, session('cart')[$product]);
        $this->get('/cart')->assertOk()->assertSee('2.500');
        $this->post('/cart/add', ['product_id' => $product, 'quantity' => 1])
            ->assertRedirect()->assertSessionHas('cart_added.quantity', 3);
        $this->assertEquals(3, session('cart')[$product]);
    }

    public function test_cart_stepper_updates_prices_and_remove_clears_item(): void
    {
        $product = DB::table('products')->insertGetId(['code' => 'G-CART', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 1250]);
        $this->withSession(['cart' => [$product => 2]])->post('/cart/update', [
            'product_id' => $product, 'quantity' => 2, 'action' => 'increase',
        ])->assertRedirect('/cart');
        $this->assertSame('3', (string) session('cart')[$product]);
        $this->get('/cart')->assertOk()->assertSee('value="3"', false)->assertSee('3.750');
        $this->post('/cart/update', ['product_id' => $product, 'quantity' => 3, 'action' => 'decrease'])->assertRedirect('/cart');
        $this->assertEquals(2, session('cart')[$product]);
        $this->get('/cart')->assertOk()->assertSee('2.500');
        $this->post('/cart/update', ['product_id' => $product, 'quantity' => 4])->assertRedirect('/cart');
        $this->get('/cart')->assertOk()->assertSee('5.000');
        $this->post('/cart/update', ['product_id' => $product, 'quantity' => 0, 'action' => 'remove'])->assertRedirect('/cart');
        $this->assertArrayNotHasKey($product, session('cart'));
        $this->get('/cart')->assertOk()->assertSee('Giỏ hàng đang trống');
    }

    public function test_cart_quantity_can_update_without_reloading_page(): void
    {
        $product = DB::table('products')->insertGetId(['code' => 'G-ASYNC', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 1250]);
        $other = DB::table('products')->insertGetId(['code' => 'X-ASYNC', 'name' => 'Xi măng', 'unit' => 'bao', 'price' => 92000]);

        $this->withSession(['cart' => [$product => 2, $other => 1]])
            ->postJson('/cart/update', ['product_id' => $product, 'quantity' => 2, 'action' => 'increase'])
            ->assertOk()
            ->assertJson(['quantity' => '3', 'lineTotal' => '3.750 ₫', 'total' => '95.750 ₫', 'canDecrease' => true]);
        $this->assertSame('3', (string) session('cart')[$product]);
    }

    public function test_catalog_and_product_page_render_from_database(): void
    {
        $product = DB::table('products')->insertGetId(['code' => 'XM1', 'name' => 'Xi măng thử', 'unit' => 'bao', 'price' => 100]);
        $this->get('/products')->assertOk()->assertSee('Xi măng thử')->assertSee('Hết hàng')->assertDontSee('Tồn kho: 0 bao');
        $this->get('/products/'.$product)->assertOk()->assertSee('Xi măng thử')->assertSee('Tồn kho tham khảo');
    }

    public function test_storefront_stock_excludes_pickup_reservations_and_stays_warehouse_specific(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $firstWarehouse = DB::table('warehouses')->insertGetId(['code' => 'K1', 'name' => 'Kho A']);
        $secondWarehouse = DB::table('warehouses')->insertGetId(['code' => 'K2', 'name' => 'Kho B']);
        $product = DB::table('products')->insertGetId(['code' => 'XM-STOCK', 'name' => 'Xi măng tồn kho', 'unit' => 'bao', 'price' => 100, 'direct_purchase_allowed' => true]);
        DB::table('inventory')->insert([
            ['warehouse_id' => $firstWarehouse, 'product_id' => $product, 'quantity' => 5],
            ['warehouse_id' => $secondWarehouse, 'product_id' => $product, 'quantity' => 2],
        ]);
        $order = app(CommerceWorkflow::class)->placePickupOrder($customer->id, [$product => 5], $firstWarehouse);

        $this->get('/products')->assertOk()->assertSee('Tồn kho: 7 bao');
        $this->get('/products/'.$product)->assertOk()->assertSee('>5 bao</strong>', false);
        app(CommerceWorkflow::class)->reviewOrder($order, $customer->id, true);
        $this->get('/products')->assertOk()->assertSee('Tồn kho: 2 bao');
        $this->get('/products/'.$product)->assertOk()
            ->assertSee('Kho A</span><strong class="is-unavailable">Hết hàng', false)
            ->assertSee('>2 bao</strong>', false)
            ->assertDontSee('Số lượng có thể thay đổi');
    }

    public function test_counted_materials_use_whole_quantities_and_bulk_materials_allow_fractions(): void
    {
        $brick = DB::table('products')->insertGetId(['code' => 'G2', 'name' => 'Gạch thử', 'unit' => 'viên', 'price' => 100]);
        $sand = DB::table('products')->insertGetId(['code' => 'C1', 'name' => 'Cát thử', 'unit' => 'm³', 'price' => 100]);

        $this->get('/products/'.$brick)->assertOk()->assertSee('step="1"', false);
        $this->get('/products/'.$sand)->assertOk()->assertSee('step="0.001"', false);
        $this->post('/cart/add', ['product_id' => $brick, 'quantity' => 1.002])->assertSessionHasErrors('quantity');
        $this->post('/cart/add', ['product_id' => $brick, 'quantity' => 2])->assertRedirect();
        $this->post('/cart/update', ['product_id' => $brick, 'quantity' => 2.5])->assertSessionHasErrors('quantity');
        $this->post('/cart/add', ['product_id' => $sand, 'quantity' => 0.5])->assertRedirect();
        $this->get('/cart')->assertOk()->assertSee('step="0.001"', false);
        $this->assertEquals(2, session('cart')[$brick]);
        $this->assertEquals(0.5, session('cart')[$sand]);
    }
}
