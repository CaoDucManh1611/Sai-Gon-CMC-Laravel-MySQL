<?php

require __DIR__.'/../laravel/vendor/autoload.php';
$app = require __DIR__.'/../laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::transaction(function (): void {
    $productCode = 'QA-LOAD-20261014';
    if (DB::table('products')->where('code', $productCode)->exists()) {
        throw new RuntimeException('QA route fixtures already exist; refusing to overwrite them.');
    }
    $customer = DB::table('users')->where('username', 'accept_20261014_customer')->value('id');
    if (! $customer) {
        throw new RuntimeException('QA customer is missing.');
    }
    $product = DB::table('products')->insertGetId([
        'category_id' => 1, 'code' => $productCode, 'name' => 'Tải mẫu QA 600 kg',
        'unit' => 'kiện', 'specification' => '600 kg; 1,5 m³', 'price' => 1000,
        'weight_kg' => 600, 'volume_m3' => 1.5, 'active' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['A', 'B'] as $stop) {
        $order = DB::table('orders')->insertGetId([
            'code' => 'QA-RUN-'.$stop.'-20261014', 'customer_id' => $customer,
            'status' => 'ready_for_delivery', 'total' => 1000, 'paid' => 1000,
            'delivery_address' => 'Điểm dừng QA '.$stop.', TP.HCM',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order, 'product_id' => $product, 'quantity' => 1,
            'unit_price' => 1000, 'line_total' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $shipment = DB::table('shipments')->insertGetId([
            'code' => 'QA-SHIP-'.$stop.'-20261014', 'order_id' => $order,
            'carrier_name' => 'QA nội bộ', 'vehicle' => 'Xe QA tuyến',
            'route_note' => 'Đơn thử tuyến', 'status' => 'planned', 'planned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        echo "stop=$stop order=$order shipment=$shipment\n";
    }
});
