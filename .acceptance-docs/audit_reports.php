<?php

require __DIR__.'/../laravel/vendor/autoload.php';
$app = require __DIR__.'/../laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach ([4, 5, 6, 7] as $id) {
    $report = DB::table('reports')->find($id);
    $snapshot = json_decode($report->snapshot, true);
    $filters = json_decode($report->filters, true);
    $from = $report->from_date.' 00:00:00';
    $to = $report->to_date.' 23:59:59';
    $cutoff = min($to, $report->created_at);
    $out = ['report' => $report->code, 'type' => $report->type, 'filters' => $filters];

    if ($id === 4) {
        $products = DB::table('products')->where('category_id', 1)->pluck('id')->all();
        $stock = DB::table('inventory as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')->whereIn('p.id', $products)
            ->select('w.name as warehouse', 'p.code as product', 'i.quantity', 'p.minimum_stock', 'i.warehouse_id', 'i.product_id')->get()
            ->map(function ($row) use ($cutoff) {
                $later = DB::table('stock_movements')->where('warehouse_id', $row->warehouse_id)
                    ->where('product_id', $row->product_id)->where('created_at', '>', $cutoff)->sum('change');
                $row->quantity = bcsub((string) $row->quantity, (string) $later, 3);
                unset($row->warehouse_id, $row->product_id);
                return $row;
            });
        $movements = DB::table('stock_movements')->whereIn('product_id', $products)
            ->whereBetween('created_at', [$from, $cutoff])->get(['product_id', 'change', 'created_at']);
        $out['source_counts'] = ['stock' => $stock->count(), 'movements' => $movements->count()];
        $out['snapshot_counts'] = ['stock' => count($snapshot['stock']), 'movements' => count($snapshot['movements'])];
        $out['stock_source'] = $stock;
        $out['stock_snapshot'] = $snapshot['stock'];
        $out['stock_rows_equal'] = canonical($stock->toArray()) === canonical($snapshot['stock']);
        $out['movement_source_sum'] = $movements->sum('change');
        $out['movement_snapshot_sum'] = array_sum(array_column($snapshot['movements'], 'change'));
        $out['movement_rows_equal'] = canonical($movements->toArray()) === canonical($snapshot['movements']);
    } elseif ($id === 5) {
        $orderIds = DB::table('orders as o')->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')->where('o.customer_id', 30)
            ->where('o.reviewed_by', 31)->where('p.category_id', 1)->distinct()->pluck('o.id')->all();
        $orders = DB::table('orders')->whereIn('id', $orderIds)->whereBetween('created_at', [$from, $cutoff])
            ->get(['code', 'status', 'total', 'paid', 'created_at']);
        $contracts = DB::table('contracts')->whereIn('order_id', $orderIds)->whereBetween('created_at', [$from, $cutoff])
            ->get(['code', 'status', 'total', 'created_at']);
        $out['source'] = ['orders' => $orders, 'contracts' => $contracts];
        $out['snapshot'] = $snapshot;
        $out['equal'] = canonical($orders->toArray()) === canonical($snapshot['orders'])
            && canonical($contracts->toArray()) === canonical($snapshot['contracts']);
    } elseif ($id === 6) {
        $orderIds = DB::table('orders')->where('customer_id', 30)->pluck('id')->all();
        $shipments = DB::table('shipments')->whereIn('order_id', $orderIds)->whereBetween('created_at', [$from, $cutoff])
            ->get(['code', 'status', 'carrier_name', 'delivered_at']);
        $returns = DB::table('returns')->whereIn('order_id', $orderIds)->whereBetween('created_at', [$from, $cutoff])
            ->get(['code', 'status', 'quantity', 'credit_amount']);
        $out['source'] = ['shipments' => $shipments, 'returns' => $returns];
        $out['snapshot'] = $snapshot;
        $out['equal'] = canonical($shipments->toArray()) === canonical($snapshot['shipments'])
            && canonical($returns->toArray()) === canonical($snapshot['returns']);
    } else {
        $productIds = DB::table('purchase_items as pi')->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->where('po.supplier_id', 1)->distinct()->pluck('pi.product_id')->all();
        $orderIds = DB::table('order_items')->whereIn('product_id', $productIds)->distinct()->pluck('order_id')->all();
        $debtIds = DB::table('debts')->whereIn('order_id', $orderIds)->orWhere('supplier_id', 1)->pluck('id')->all();
        $payments = DB::table('payments')->whereIn('debt_id', $debtIds)->whereBetween('paid_at', [$from, $cutoff])
            ->select('method')->selectRaw('COUNT(*) as transactions, SUM(amount) as amount')->groupBy('method')->get();
        $debts = DB::table('debts as d')->leftJoin('orders as o', 'o.id', '=', 'd.order_id')
            ->leftJoin('contracts as c', 'c.id', '=', 'o.contract_id')
            ->whereIn('d.id', $debtIds)->where('d.created_at', '<=', $cutoff)
            ->get(['d.id', 'd.direction', 'd.amount', 'o.status as order_status', 'o.requires_contract', 'c.status as contract_status']);
        $sourceDebtTotals = [];
        foreach ($debts as $debt) {
            if ($debt->direction === 'receivable' && (in_array($debt->order_status, ['rejected', 'cancelled'])
                || ($debt->requires_contract && $debt->contract_status !== 'signed'))) continue;
            $offsets = DB::table('customer_credit_movements')->where('debt_id', $debt->id)->where('type', 'offset')->where('created_at', '<=', $cutoff)->sum('amount');
            $paid = DB::table('payments')->where('debt_id', $debt->id)->where('paid_at', '<=', $cutoff)->sum('amount');
            $sourceDebtTotals[$debt->direction]['amount'] = ($sourceDebtTotals[$debt->direction]['amount'] ?? 0) + (float) $debt->amount;
            $sourceDebtTotals[$debt->direction]['paid'] = ($sourceDebtTotals[$debt->direction]['paid'] ?? 0) + min((float) $debt->amount, $offsets + $paid);
        }
        $out['payment_source'] = $payments;
        $out['payment_snapshot'] = $snapshot['payments'];
        $out['payment_equal'] = canonical($payments->toArray()) === canonical($snapshot['payments']);
        $out['debt_source'] = $sourceDebtTotals;
        $out['debt_snapshot'] = $snapshot['debts'];
        $out['debt_ids'] = $debtIds;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
}

function canonical(array $rows): array {
    $rows = array_map(static function ($row) {
        $row = (array) $row;
        ksort($row);
        foreach ($row as &$value) if (is_numeric($value)) $value = (float) $value;
        return $row;
    }, $rows);
    usort($rows, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
    return $rows;
}
