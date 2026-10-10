<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private function allowedTypes(string $role): array
    {
        return match ($role) {
            'sales' => ['sales'], 'warehouse' => ['inventory', 'logistics'],
            'accounting' => ['finance'], 'delivery' => ['logistics'],
            default => ['finance', 'inventory', 'sales', 'logistics'],
        };
    }

    public function index(Request $request): View
    {
        return view('staff.reports', [
            'reports' => DB::table('reports')->whereIn('type', $this->allowedTypes($request->user()->role))->orderByDesc('id')->paginate(20),
            'allowedTypes' => $this->allowedTypes($request->user()->role),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['finance', 'inventory', 'sales', 'logistics'])],
            'from_date' => ['required', 'date'], 'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);
        abort_unless(in_array($data['type'], $this->allowedTypes($request->user()->role), true), 403);
        $from = $data['from_date'].' 00:00:00';
        $to = $data['to_date'].' 23:59:59';
        $snapshot = match ($data['type']) {
            'finance' => [
                'payments' => DB::table('payments')->whereBetween('paid_at', [$from, $to])->select('method')->selectRaw('COUNT(*) as transactions, SUM(amount) as amount')->groupBy('method')->get(),
                'debts' => $this->debtsAsOf($to),
            ],
            'inventory' => [
                'stock' => $this->stockAsOf($to),
                'movements' => DB::table('stock_movements')->whereBetween('created_at', [$from, $to])->select('product_id', 'change', 'created_at')->get(),
            ],
            'sales' => [
                'orders' => DB::table('orders')->whereBetween('created_at', [$from, $to])->select('code', 'status', 'total', 'paid', 'created_at')->get(),
                'contracts' => DB::table('contracts')->whereBetween('created_at', [$from, $to])->select('code', 'status', 'total', 'created_at')->get(),
            ],
            'logistics' => [
                'shipments' => DB::table('shipments')->whereBetween('created_at', [$from, $to])->select('code', 'status', 'carrier_name', 'delivered_at')->get(),
                'returns' => DB::table('returns')->whereBetween('created_at', [$from, $to])->select('code', 'status', 'quantity', 'credit_amount')->get(),
            ],
        };
        $id = DB::table('reports')->insertGetId([
            'code' => 'TEMP-'.uniqid(), 'type' => $data['type'], 'created_by' => $request->user()->id,
            'from_date' => $data['from_date'], 'to_date' => $data['to_date'],
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('reports')->where('id', $id)->update(['code' => 'BC-'.str_pad($id, 6, '0', STR_PAD_LEFT)]);

        return redirect()->route('staff.reports.index')->with('success', 'Đã lập và trình báo cáo.');
    }

    private function debtsAsOf(string $to): array
    {
        $payments = DB::table('payments')->where('paid_at', '<=', $to)->groupBy('debt_id')
            ->select('debt_id')->selectRaw('SUM(amount) as total')->pluck('total', 'debt_id');
        $offsets = DB::table('customer_credit_movements')->where('type', 'offset')->where('created_at', '<=', $to)
            ->groupBy('debt_id')->select('debt_id')->selectRaw('SUM(amount) as total')->pluck('total', 'debt_id');
        $laterReturns = DB::table('returns')->where('status', 'completed')->where('updated_at', '>', $to)
            ->groupBy('order_id')->select('order_id')->selectRaw('SUM(credit_amount) as total')->pluck('total', 'order_id');
        $laterSupplierCredits = DB::table('supplier_returns')->where('settled_at', '>', $to)
            ->groupBy('debt_id')->select('debt_id')->selectRaw('SUM(credit_amount) as total')->pluck('total', 'debt_id');
        $totals = [];
        foreach (DB::table('debts')->where('created_at', '<=', $to)->get() as $debt) {
            $direction = $debt->direction;
            $totals[$direction] ??= ['direction' => $direction, 'amount' => '0.00', 'paid' => '0.00'];
            $laterCredit = $direction === 'receivable'
                ? ($laterReturns[$debt->order_id] ?? '0') : ($laterSupplierCredits[$debt->id] ?? '0');
            $amount = bcadd((string) $debt->amount, (string) $laterCredit, 2);
            $paid = bcadd((string) ($payments[$debt->id] ?? '0'), (string) ($offsets[$debt->id] ?? '0'), 2);
            $totals[$direction]['amount'] = bcadd($totals[$direction]['amount'], $amount, 2);
            $totals[$direction]['paid'] = bcadd($totals[$direction]['paid'],
                bccomp($paid, $amount, 2) > 0 ? $amount : $paid, 2);
        }

        return array_values($totals);
    }

    private function stockAsOf(string $to): array
    {
        $laterChanges = DB::table('stock_movements')->where('created_at', '>', $to)
            ->groupBy('warehouse_id', 'product_id')->select('warehouse_id', 'product_id')
            ->selectRaw('SUM(`change`) as total')->get()->keyBy(fn ($row) => $row->warehouse_id.':'.$row->product_id);

        return DB::table('inventory')->join('products', 'products.id', '=', 'inventory.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory.warehouse_id')
            ->select('warehouses.name as warehouse', 'products.code as product', 'inventory.quantity',
                'products.minimum_stock', 'inventory.warehouse_id', 'inventory.product_id', 'inventory.created_at as stocked_at')
            ->get()->map(function ($row) use ($laterChanges, $to) {
                $row->quantity = $row->stocked_at && $row->stocked_at > $to ? '0.000' : bcsub(
                    (string) $row->quantity,
                    (string) ($laterChanges[$row->warehouse_id.':'.$row->product_id]->total ?? '0'), 3);
                unset($row->warehouse_id, $row->product_id, $row->stocked_at);

                return $row;
            })->all();
    }

    public function review(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'string', 'max:2000']]);
        $changed = DB::table('reports')->where('id', $id)->where('status', 'pending')->update([
            'status' => $data['decision'], 'approved_by' => $request->user()->id,
            'review_note' => $data['note'] ?? null, 'updated_at' => now(),
        ]);
        abort_unless($changed, 422);

        return back()->with('success', 'Đã xử lý báo cáo.');
    }

    public function print(Request $request, int $id): View
    {
        $report = DB::table('reports')->where('id', $id)->where('status', 'approved')->firstOrFail();
        abort_unless(in_array($report->type, $this->allowedTypes($request->user()->role), true), 403);
        $snapshot = json_decode($report->snapshot, true, flags: JSON_THROW_ON_ERROR);

        return view('staff.report-print', compact('report', 'snapshot'));
    }

    public function export(Request $request, int $id): StreamedResponse
    {
        $report = DB::table('reports')->where('id', $id)->where('status', 'approved')->firstOrFail();
        abort_unless(in_array($report->type, $this->allowedTypes($request->user()->role), true), 403);
        $snapshot = json_decode($report->snapshot, true, flags: JSON_THROW_ON_ERROR);

        return response()->streamDownload(function () use ($report, $snapshot) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Báo cáo', $report->code, 'Từ ngày', $report->from_date, 'Đến ngày', $report->to_date]);
            foreach ($snapshot as $section => $rows) {
                fputcsv($out, [$section]);
                if (! $rows) {
                    continue;
                }
                fputcsv($out, array_keys((array) $rows[0]));
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-]/', $value) ? '\''.$value : $value, array_values((array) $row)));
                }
            }
            fclose($out);
        }, $report->code.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
