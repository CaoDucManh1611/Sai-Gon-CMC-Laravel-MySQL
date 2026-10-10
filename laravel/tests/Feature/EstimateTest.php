<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstimateTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseeding_does_not_overwrite_approved_product_price(): void
    {
        $this->seed();
        DB::table('products')->where('code', 'PCB40')->update(['price' => 123456]);
        $this->seed();
        $this->assertDatabaseHas('products', ['code' => 'PCB40', 'price' => 123456]);
    }

    public function test_legacy_norms_are_calculated_and_guest_prices_are_hidden(): void
    {
        $this->seed();
        $url = '/estimate?building_type=NHA_PHO_BTCT&area=100&floors=2';
        $this->get($url)->assertOk()->assertSee('300 bao')->assertSee('5 m³')->assertDontSee('Tạm tính:');
        $this->actingAs(User::factory()->create(['role' => 'customer', 'status' => 'active']))
            ->get($url)->assertOk()->assertSee('Tạm tính:');
    }
}
