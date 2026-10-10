<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_logs_back_in_with_registered_phone_not_full_name(): void
    {
        $this->post('/register', [
            'name' => 'Khách Hàng Thử', 'phone' => '090 123 4568', 'address' => 'Địa chỉ thử',
            'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123',
        ])->assertRedirect('/products');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['phone' => '0901234568', 'username' => '0901234568']);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->post('/login', ['login' => 'Khách Hàng Thử', 'password' => 'StrongPass123'])
            ->assertSessionHasErrors('login');
        $this->post('/login', ['login' => '0901234568', 'password' => 'StrongPass123'])
            ->assertRedirect('/products');
        $this->assertAuthenticated();
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
        $response = $this->post('/register', [
            'name' => 'Nguyễn Văn A', 'phone' => '0901234567', 'address' => 'Công trình A',
            'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123',
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
            ->assertOk()->assertSee('value="4"', false)->assertSee('Lưu số lượng vào giỏ');
        $this->post('/cart/add', ['product_id' => $product, 'quantity' => 2, 'replace' => 1])
            ->assertRedirect()->assertSessionHas('cart_added.quantity', 2);
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
        $this->assertEquals(3, session('cart')[$product]);
        $this->get('/cart')->assertOk()->assertSee('3.750');
        $this->post('/cart/update', ['product_id' => $product, 'quantity' => 3, 'action' => 'decrease'])->assertRedirect('/cart');
        $this->assertEquals(2, session('cart')[$product]);
        $this->get('/cart')->assertOk()->assertSee('2.500');
        $this->post('/cart/update', ['product_id' => $product, 'quantity' => 4])->assertRedirect('/cart');
        $this->get('/cart')->assertOk()->assertSee('5.000');
        $this->post('/cart/update', ['product_id' => $product, 'quantity' => 0, 'action' => 'remove'])->assertRedirect('/cart');
        $this->assertArrayNotHasKey($product, session('cart'));
        $this->get('/cart')->assertOk()->assertSee('Giỏ hàng đang trống');
    }

    public function test_catalog_and_product_page_render_from_database(): void
    {
        $product = DB::table('products')->insertGetId(['code' => 'XM1', 'name' => 'Xi măng thử', 'unit' => 'bao', 'price' => 100]);
        $this->get('/products')->assertOk()->assertSee('Xi măng thử');
        $this->get('/products/'.$product)->assertOk()->assertSee('Xi măng thử');
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
