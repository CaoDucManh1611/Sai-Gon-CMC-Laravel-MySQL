<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CatalogWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_proposal_changes_product_only_after_management_approval(): void
    {
        $staff = User::factory()->create();
        $manager = User::factory()->create();
        $category = DB::table('categories')->insertGetId(['code' => 'GACH', 'name' => 'Gạch']);
        $workflow = app(CatalogWorkflow::class);
        $proposal = $workflow->propose('product_create', null, [
            'category_id' => $category, 'code' => 'G-01', 'name' => 'Gạch tuynel',
            'unit' => 'viên', 'price' => '1200.00', 'active' => true,
        ], $staff->id);
        $this->assertDatabaseCount('products', 0);
        $workflow->review($proposal, $manager->id, true);
        $this->assertDatabaseHas('products', ['code' => 'G-01', 'price' => 1200]);
        $this->assertDatabaseCount('price_history', 1);
        $product = DB::table('products')->where('code', 'G-01')->first();
        $change = $workflow->propose('product_update', $product->id, ['price' => '1300.00'], $staff->id);
        $this->assertEquals(1200, DB::table('products')->find($product->id)->price);
        $workflow->review($change, $manager->id, true);
        $this->assertEquals(1300, DB::table('products')->find($product->id)->price);
        $this->assertDatabaseCount('price_history', 2);
    }
}
