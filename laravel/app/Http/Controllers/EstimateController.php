<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EstimateController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'building_type' => ['nullable', 'in:NHA_CAP_4,NHA_PHO_BTCT,BIET_THU'],
            'area' => ['nullable', 'numeric', 'gt:0', 'lte:10000'],
            'floors' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $rows = collect();
        $total = '0.00';
        if (isset($data['building_type'], $data['area'])) {
            $rows = DB::table('estimation_norms')->join('products', 'products.id', '=', 'estimation_norms.product_id')
                ->where('estimation_norms.building_type', $data['building_type'])->where('products.active', true)
                ->select('estimation_norms.*', 'products.name', 'products.unit', 'products.price')->get()
                ->map(function ($row) use ($data, &$total) {
                    $quantity = bcmul((string) $row->quantity_per_square_meter, (string) $data['area'], 4);
                    if ($row->per_floor) {
                        $quantity = bcmul($quantity, (string) ($data['floors'] ?? 1), 4);
                    }
                    $multiplier = $row->rounding === 'tenth' ? 10 : 1;
                    $row->quantity = (string) (ceil((float) $quantity * $multiplier) / $multiplier);
                    $row->line_total = bcmul($row->quantity, (string) $row->price, 2);
                    $total = bcadd($total, $row->line_total, 2);

                    return $row;
                });
        }

        return view('store.estimate', compact('data', 'rows', 'total'));
    }
}
