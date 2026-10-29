<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CommerceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_sales_can_export_a_confirmed_contract_without_marking_it_signed(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $product = DB::table('products')->insertGetId(['code' => 'PDF-01', 'name' => 'Cát vàng xây tô', 'unit' => 'm³', 'price' => 180000]);
        $workflow = app(CommerceWorkflow::class);
        $quote = $workflow->requestQuote($customer->id, [$product => 5], 'Công trình thử nghiệm');
        $workflow->sendQuote($quote, $sales->id, [], now()->addWeek()->toDateString());
        $order = $workflow->acceptQuote($quote, $customer->id);
        $workflow->reviewOrder($order, $sales->id, true);
        $contract = $workflow->createContract($order, $sales->id, '200000', 'Cọc trước, trả phần còn lại khi nhận hàng.', 'Giao tại công trình.');
        $url = route('staff.contracts.pdf', $contract);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($customer)->get($url)->assertForbidden();
        $this->actingAs($sales)->get($url)->assertStatus(409);
        $workflow->submitContract($contract);
        $workflow->reviewContract($contract, $manager->id, true);
        $this->get($url)->assertStatus(409);
        $workflow->respondToContract($contract, $customer->id, true);
        $this->get('/staff/work/contracts')->assertOk()->assertSee('Xuất hợp đồng PDF');
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
        $this->assertDatabaseHas('contracts', ['id' => $contract, 'status' => 'approved', 'document_path' => null, 'signed_at' => null]);

        $workflow->cancelContract($contract, $manager->id, 'Dừng đơn hàng');
        $this->get($url)->assertStatus(409);
    }
}
