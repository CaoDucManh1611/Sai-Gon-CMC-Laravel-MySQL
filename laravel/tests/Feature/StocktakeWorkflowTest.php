<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FulfillmentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StocktakeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_stocktake_changes_inventory_only_after_review_and_rejects_stale_count(): void
    {
        $staff = User::factory()->create();
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'K', 'name' => 'Kho']);
        $product = DB::table('products')->insertGetId(['code' => 'G', 'name' => 'Gạch', 'unit' => 'viên', 'price' => 10]);
        DB::table('inventory')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 10]);
        $workflow = app(FulfillmentWorkflow::class);
        $id = $workflow->proposeStocktake($warehouse, $product, $staff->id, '8', 'Vỡ trong kho');
        $this->assertEquals(10, DB::table('inventory')->first()->quantity);
        $workflow->reviewStocktake($id, $staff->id, true);
        $this->assertEquals(8, DB::table('inventory')->first()->quantity);
        $this->assertEquals(-2, DB::table('stock_movements')->first()->change);
        $stale = $workflow->proposeStocktake($warehouse, $product, $staff->id, '7', 'Kiểm tra lại');
        DB::table('inventory')->update(['quantity' => 9]);
        $this->expectException(ValidationException::class);
        $workflow->reviewStocktake($stale, $staff->id, true);
    }
}
