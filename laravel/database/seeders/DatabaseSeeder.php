<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['XI-MANG', 'Xi măng'], ['GACH', 'Gạch xây dựng'], ['THEP', 'Sắt thép'],
            ['CAT-DA', 'Cát & đá'], ['VAT-TU-PHU', 'Vật tư phụ'],
        ];
        foreach ($categories as [$code,$name]) {
            DB::table('categories')->insertOrIgnore(['code' => $code, 'name' => $name, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        $categoryIds = DB::table('categories')->pluck('id', 'code');
        $products = [
            ['GACH', 'GACH-TUYNEL-2', 'Gạch tuynel 2 lỗ', 'viên', '180 × 80 × 80 mm', 1250, 'mat-brick'],
            ['XI-MANG', 'PCB40', 'Xi măng PCB40', 'bao', 'Bao 50 kg', 92000, 'mat-cement'],
            ['THEP', 'THEP-PHI12', 'Thép cây Ø12', 'cây', 'Cây 11,7 m', 128000, 'mat-steel'],
            ['CAT-DA', 'CAT-VANG', 'Cát vàng xây tô', 'm³', 'Cát hạt vừa', 280000, 'mat-sand'],
            ['CAT-DA', 'DA-1X2', 'Đá dăm 1×2', 'm³', 'Cỡ hạt 10–20 mm', 350000, 'mat-stone'],
            ['GACH', 'GACH-BLOCK', 'Gạch block bê tông', 'viên', '390 × 190 × 190 mm', 11500, 'mat-block'],
            ['GACH', 'GACH-TUYNEL-4', 'Gạch tuynel 4 lỗ', 'viên', '180 × 80 × 80 mm', 1450, 'mat-brick'],
            ['GACH', 'GACH-LAT', 'Gạch lát sân tự chèn', 'viên', 'Dày 60 mm', 9800, 'mat-paver'],
            ['GACH', 'NGOI-DAT', 'Ngói đất nung sóng lớn', 'viên', 'Ngói lợp mái', 13500, 'mat-roof'],
            ['XI-MANG', 'PCB30', 'Xi măng PCB30', 'bao', 'Bao 50 kg', 79000, 'mat-cement'],
            ['XI-MANG', 'XI-MANG-TRANG', 'Xi măng trắng', 'bao', 'Bao 40 kg', 118000, 'mat-cement'],
            ['THEP', 'THEP-PHI10', 'Thép cây Ø10', 'cây', 'Cây 11,7 m', 92000, 'mat-steel'],
            ['THEP', 'LUOI-THEP', 'Lưới thép hàn', 'cuộn', 'Cuộn 1 × 10 m', 485000, 'mat-mesh'],
            ['CAT-DA', 'CAT-DEN', 'Cát đen san lấp', 'm³', 'Cát san nền', 180000, 'mat-sand'],
            ['CAT-DA', 'DA-4X6', 'Đá dăm 4×6', 'm³', 'Cỡ hạt 40–60 mm', 315000, 'mat-stone'],
            ['VAT-TU-PHU', 'CHONG-THAM', 'Chống thấm gốc xi măng', 'thùng', 'Thùng 18 kg', 645000, 'mat-waterproof'],
            ['VAT-TU-PHU', 'GACH-OP', 'Gạch ốp lát 60×60', 'hộp', 'Hộp 4 viên', 295000, 'mat-tile'],
            ['VAT-TU-PHU', 'ONG-PVC-90', 'Ống PVC Ø90', 'cây', 'Cây 4 m', 87000, 'mat-pipe'],
        ];
        foreach ($products as [$category,$code,$name,$unit,$spec,$price,$image]) {
            DB::table('products')->insertOrIgnore(
                ['code' => $code, 'category_id' => $categoryIds[$category], 'name' => $name, 'unit' => $unit,
                    'specification' => $spec, 'price' => $price, 'image_path' => $image, 'active' => true,
                    'direct_purchase_allowed' => true,
                    'updated_at' => now(), 'created_at' => now()]);
        }
        DB::table('products')->insertOrIgnore(['code' => 'THEP-KG',
            'category_id' => $categoryIds['THEP'], 'name' => 'Thép xây dựng theo kg',
            'unit' => 'kg', 'specification' => 'Đơn giá minh họa, cần xác nhận khi báo giá',
            'price' => 15500, 'image_path' => 'mat-steel', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productIds = DB::table('products')->pluck('id', 'code');
        $norms = [
            'NHA_CAP_4' => [['PCB40', 1.25, false, 'whole'], ['CAT-VANG', 0.08, false, 'tenth'], ['DA-1X2', 0.06, false, 'tenth'], ['THEP-KG', 15, false, 'whole'], ['GACH-TUYNEL-2', 68, false, 'whole']],
            'NHA_PHO_BTCT' => [['PCB40', 1.50, true, 'whole'], ['CAT-VANG', 0.09, true, 'tenth'], ['DA-1X2', 0.05, false, 'tenth'], ['THEP-KG', 25, true, 'whole'], ['GACH-TUYNEL-2', 75, true, 'whole']],
            'BIET_THU' => [['PCB40', 1.80, true, 'whole'], ['CAT-VANG', 0.11, true, 'tenth'], ['DA-1X2', 0.08, false, 'tenth'], ['THEP-KG', 35, true, 'whole'], ['GACH-TUYNEL-2', 90, true, 'whole']],
        ];
        foreach ($norms as $building => $rules) {
            foreach ($rules as [$code,$quantity,$perFloor,$rounding]) {
                DB::table('estimation_norms')->insertOrIgnore(
                    ['building_type' => $building, 'product_id' => $productIds[$code],
                        'quantity_per_square_meter' => $quantity, 'per_floor' => $perFloor, 'rounding' => $rounding,
                        'source' => 'Định mức minh họa; cần kỹ sư kiểm tra theo bản vẽ',
                        'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
        DB::table('warehouses')->insertOrIgnore(['code' => 'KHO-CHINH', 'name' => 'Kho chính Sài Gòn CMC', 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
    }
}
