<?php

namespace App\Http\Controllers;

use Dompdf\Dompdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
            'categories' => DB::table('categories')->orderBy('name')->get(['id', 'name']),
            'customers' => DB::table('users')->where('role', 'customer')->orderBy('name')->get(['id', 'name']),
            'staffMembers' => DB::table('users')->whereIn('role', ['sales', 'warehouse', 'accounting', 'delivery'])->orderBy('name')->get(['id', 'name']),
            'suppliers' => DB::table('suppliers')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['finance', 'inventory', 'sales', 'logistics'])],
            'from_date' => ['required', 'date'], 'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
        ]);
        abort_unless(in_array($data['type'], $this->allowedTypes($request->user()->role), true), 403);
        $filters = array_filter(array_intersect_key($data, array_flip(['category_id', 'customer_id', 'staff_id', 'supplier_id'])),
            static fn ($value) => $value !== null && $value !== '');
        if ($data['type'] === 'inventory' && (isset($filters['customer_id']) || isset($filters['staff_id']))) {
            return back()->withErrors(['filter' => 'Báo cáo tồn kho chỉ lọc theo nhóm vật liệu hoặc nhà cung cấp.'])->withInput();
        }
        $from = $data['from_date'].' 00:00:00';
        $to = $data['to_date'].' 23:59:59';
        $productIds = null;
        if (isset($filters['category_id']) || isset($filters['supplier_id'])) {
            $products = DB::table('products');
            if (isset($filters['category_id'])) {
                $products->where('category_id', $filters['category_id']);
            }
            if (isset($filters['supplier_id'])) {
                $products->whereIn('id', DB::table('purchase_items')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_items.purchase_order_id')
                    ->where('purchase_orders.supplier_id', $filters['supplier_id'])->select('purchase_items.product_id'));
            }
            $productIds = $products->pluck('id')->all();
        }
        $orderIds = null;
        if ($productIds !== null || isset($filters['customer_id']) || isset($filters['staff_id'])) {
            $orders = DB::table('orders');
            if (isset($filters['customer_id'])) {
                $orders->where('customer_id', $filters['customer_id']);
            }
            if (isset($filters['staff_id'])) {
                $orders->where('reviewed_by', $filters['staff_id']);
            }
            if ($productIds !== null) {
                $orders->whereIn('id', DB::table('order_items')->whereIn('product_id', $productIds)->select('order_id'));
            }
            $orderIds = $orders->pluck('id')->all();
        }
        $payments = DB::table('payments')->whereBetween('paid_at', [$from, $to]);
        if ($orderIds !== null || isset($filters['supplier_id'])) {
            $payments->whereIn('debt_id', DB::table('debts')->where(function ($query) use ($orderIds, $filters) {
                if ($orderIds !== null) {
                    $query->whereIn('order_id', $orderIds);
                }
                if (isset($filters['supplier_id'])) {
                    $query->orWhere('supplier_id', $filters['supplier_id']);
                }
            })->select('id'));
        }
        $stockMovements = DB::table('stock_movements')->whereBetween('created_at', [$from, $to]);
        if ($productIds !== null) {
            $stockMovements->whereIn('product_id', $productIds);
        }
        $orders = DB::table('orders')->whereBetween('created_at', [$from, $to]);
        $contracts = DB::table('contracts')->whereBetween('created_at', [$from, $to]);
        $shipments = DB::table('shipments')->whereBetween('created_at', [$from, $to]);
        $returns = DB::table('returns')->whereBetween('created_at', [$from, $to]);
        if ($orderIds !== null) {
            $orders->whereIn('id', $orderIds);
            $contracts->whereIn('order_id', $orderIds);
            $shipments->whereIn('order_id', $orderIds);
            $returns->whereIn('order_id', $orderIds);
        }
        $snapshot = match ($data['type']) {
            'finance' => [
                'payments' => $payments->select('method')->selectRaw('COUNT(*) as transactions, SUM(amount) as amount')->groupBy('method')->get(),
                'debts' => $this->debtsAsOf($to, $orderIds, $filters['supplier_id'] ?? null),
            ],
            'inventory' => [
                'stock' => $this->stockAsOf($to, $productIds),
                'movements' => $stockMovements->select('product_id', 'change', 'created_at')->get(),
            ],
            'sales' => [
                'orders' => $orders->select('code', 'status', 'total', 'paid', 'created_at')->get(),
                'contracts' => $contracts->select('code', 'status', 'total', 'created_at')->get(),
            ],
            'logistics' => [
                'shipments' => $shipments->select('code', 'status', 'carrier_name', 'delivered_at')->get(),
                'returns' => $returns->select('code', 'status', 'quantity', 'credit_amount')->get(),
            ],
        };
        $id = DB::table('reports')->insertGetId([
            'code' => 'TEMP-'.uniqid(), 'type' => $data['type'], 'created_by' => $request->user()->id,
            'from_date' => $data['from_date'], 'to_date' => $data['to_date'],
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('reports')->where('id', $id)->update(['code' => 'BC-'.str_pad($id, 6, '0', STR_PAD_LEFT)]);

        return redirect()->route('staff.reports.index')->with('success', 'Đã lập và trình báo cáo.');
    }

    private function debtsAsOf(string $to, ?array $orderIds = null, ?int $supplierId = null): array
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
        $debtQuery = DB::table('debts')->leftJoin('orders', 'orders.id', '=', 'debts.order_id')
            ->leftJoin('contracts', 'contracts.id', '=', 'orders.contract_id')
            ->where('debts.created_at', '<=', $to)
            ->select('debts.*', 'orders.status as order_status', 'orders.requires_contract',
                'contracts.status as contract_status', 'contracts.signed_at');
        if ($orderIds !== null || $supplierId !== null) {
            $debtQuery->where(function ($query) use ($orderIds, $supplierId) {
                if ($orderIds !== null) {
                    $query->whereIn('debts.order_id', $orderIds);
                }
                if ($supplierId !== null) {
                    $query->orWhere('debts.supplier_id', $supplierId);
                }
            });
        }
        $debts = $debtQuery->get();
        foreach ($debts as $debt) {
            if ($debt->direction === 'receivable' && (in_array($debt->order_status, ['rejected', 'cancelled'], true)
                || ($debt->requires_contract && ($debt->contract_status !== 'signed'
                    || ($debt->signed_at && $debt->signed_at > $to))))) {
                continue;
            }
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

    private function stockAsOf(string $to, ?array $productIds = null): array
    {
        $laterChanges = DB::table('stock_movements')->where('created_at', '>', $to)
            ->groupBy('warehouse_id', 'product_id')->select('warehouse_id', 'product_id')
            ->selectRaw('SUM(`change`) as total')->get()->keyBy(fn ($row) => $row->warehouse_id.':'.$row->product_id);

        $stock = DB::table('inventory')->join('products', 'products.id', '=', 'inventory.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory.warehouse_id')
            ->select('warehouses.name as warehouse', 'products.code as product', 'inventory.quantity',
                'products.minimum_stock', 'inventory.warehouse_id', 'inventory.product_id', 'inventory.created_at as stocked_at');
        if ($productIds !== null) {
            $stock->whereIn('inventory.product_id', $productIds);
        }

        return $stock->get()->map(function ($row) use ($laterChanges, $to) {
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
            $workbook = new Spreadsheet;
            $sheets = ['Thông tin' => [['Báo cáo', $report->code], ['Từ ngày', $report->from_date], ['Đến ngày', $report->to_date]]];
            foreach ($snapshot as $section => $rows) {
                $sheets[$section] = $rows
                    ? array_merge([array_keys((array) $rows[0])], array_map('array_values', $rows))
                    : [['Không có dữ liệu trong kỳ']];
            }
            foreach ($sheets as $section => $rows) {
                $sheet = $section === 'Thông tin' ? $workbook->getActiveSheet() : $workbook->createSheet();
                $sheet->setTitle($section);
                foreach ($rows as $rowIndex => $row) {
                    foreach ($row as $columnIndex => $value) {
                        $field = $rows[0][$columnIndex] ?? '';
                        $number = $section !== 'Thông tin' && $rowIndex > 0
                            && in_array($field, ['amount', 'paid', 'quantity', 'total', 'transactions', 'minimum_stock', 'change', 'credit_amount'], true)
                            && is_numeric($value);
                        $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], (string) $value,
                            $number ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }
                }
                $sheet->freezePane('A2');
            }
            (new Xlsx($workbook))->save('php://output');
            $workbook->disconnectWorksheets();
        }, $report->code.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function downloadPdf(Request $request, int $id): StreamedResponse
    {
        $report = DB::table('reports')->where('id', $id)->where('status', 'approved')->firstOrFail();
        abort_unless(in_array($report->type, $this->allowedTypes($request->user()->role), true), 403);
        $snapshot = json_decode($report->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $pdf = new Dompdf;
        $pdf->loadHtml(view('staff.report-print', ['report' => $report, 'snapshot' => $snapshot, 'download' => true])->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return response()->streamDownload(static function () use ($pdf): void {
            echo $pdf->output();
        }, $report->code.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
