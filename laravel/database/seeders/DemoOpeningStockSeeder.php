<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoOpeningStockSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            if (DB::table('stock_documents')->where('code', 'TON-DAU-KY-DEMO')->exists()) {
                return;
            }
            $warehouse = DB::table('warehouses')->where('active', true)->orderBy('id')->first();
            $staff = DB::table('users')->whereIn('role', ['warehouse', 'admin'])->where('status', 'active')->orderBy('id')->first();
            if (! $warehouse || ! $staff) {
                throw new RuntimeException('Cần có kho và nhân viên kho/quản trị đang hoạt động trước khi tạo tồn đầu kỳ minh họa.');
            }
            $document = DB::table('stock_documents')->insertGetId([
                'code' => 'TON-DAU-KY-DEMO', 'type' => 'opening', 'warehouse_id' => $warehouse->id,
                'created_by' => $staff->id, 'approved_by' => $staff->id, 'status' => 'approved',
                'note' => 'Tồn đầu kỳ minh họa cho đồ án; thay bằng phiếu nhập thực tế khi vận hành.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (DB::table('products')->where('active', true)->get() as $product) {
                if ($product->code === 'NGOI-DAT') {
                    DB::table('inventory')->insertOrIgnore([
                        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
                        'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);

                    continue;
                }
                $existing = DB::table('inventory')->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity') ?? 0;
                if (bccomp((string) $existing, '0', 3) > 0) {
                    continue;
                }
                $quantity = match ($product->unit) {
                    'm³' => 30, 'viên' => 3000, default => 100,
                };
                DB::table('inventory')->updateOrInsert(
                    ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
                    ['quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()]
                );
                DB::table('stock_document_items')->insert([
                    'stock_document_id' => $document, 'product_id' => $product->id,
                    'quantity' => $quantity, 'unit_price' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('stock_movements')->insert([
                    'stock_document_id' => $document, 'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id, 'change' => $quantity, 'balance_after' => $quantity,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
