<?php

namespace App\Http\Controllers;

use App\Services\CommerceWorkflow;
use App\Services\FulfillmentWorkflow;
use App\Services\PaymentScheduleWorkflow;
use App\Support\UiFormat;
use Dompdf\Dompdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class StaffController extends Controller
{
    public function dashboard(Request $request): View
    {
        $section = $request->route('section');
        $contractsQuery = DB::table('contracts')->leftJoin('orders', 'orders.id', '=', 'contracts.order_id')
            ->leftJoin('quotes', 'quotes.id', '=', 'contracts.quote_id')
            ->select('contracts.*', 'orders.code as order_code')
            ->when($request->filled('contract'), fn ($query) => $query->where('contracts.code', 'like', '%'.$request->query('contract').'%'))
            ->orderByDesc('contracts.id');
        $contracts = $section === 'contracts' ? $contractsQuery->paginate(30)->withQueryString() : $contractsQuery->limit(30)->get();
        $salesOrdersQuery = DB::table('orders')->join('users', 'users.id', '=', 'orders.customer_id')
            ->leftJoin('contracts', 'contracts.order_id', '=', 'orders.id')
            ->leftJoin('quotes', 'quotes.id', '=', 'orders.quote_id')
            ->select('orders.*', 'users.name as customer_name', 'contracts.code as draft_contract_code', 'quotes.staff_note as quote_stock_note')
            ->when($section === 'sales_orders' && $request->filled('q'), fn ($query) => $query->where(fn ($matches) => $matches->where('orders.code', 'like', '%'.$request->query('q').'%')->orWhere('users.name', 'like', '%'.$request->query('q').'%')))
            ->when($section === 'sales_orders' && $request->filled('status'), fn ($query) => $query->where('orders.status', $request->query('status')))
            ->orderByDesc('orders.id');
        $salesOrders = $section === 'sales_orders'
            ? $salesOrdersQuery->paginate(30)->withQueryString()
            : $salesOrdersQuery->limit(100)->get();
        $quotesQuery = DB::table('quotes')->join('users', 'users.id', '=', 'quotes.customer_id')
            ->select('quotes.*', 'users.name as customer_name')
            ->when($section === 'quotes' && $request->filled('q'), fn ($query) => $query->where(fn ($matches) => $matches->where('quotes.code', 'like', '%'.$request->query('q').'%')->orWhere('users.name', 'like', '%'.$request->query('q').'%')))
            ->when($section === 'quotes' && $request->filled('status'), function ($query) use ($request) {
                $request->query('status') === 'actionable'
                    ? $query->whereIn('quotes.status', ['requested', 'draft'])
                    : $query->where('quotes.status', $request->query('status'));
            })
            ->orderBy('quotes.id', $section === 'quotes' && $request->query('sort') === 'oldest' ? 'asc' : 'desc');
        $quotes = $section === 'quotes' ? $quotesQuery->paginate(30)->withQueryString() : $quotesQuery->limit(30)->get();
        $debtsQuery = DB::table('debts')->leftJoin('orders', 'orders.id', '=', 'debts.order_id')
            ->leftJoin('contracts', 'contracts.id', '=', 'orders.contract_id')
            ->select('debts.*', 'orders.status as order_status', 'orders.requires_contract',
                'orders.code as order_code', 'contracts.code as contract_code', 'contracts.status as contract_status')
            ->orderByDesc('debts.id');
        $debts = $section === 'finance'
            ? $debtsQuery->paginate(30, ['*'], 'debts_page')->withQueryString()
            : $debtsQuery->limit(30)->get();
        $contractDebtIds = DB::table('debts')->whereIn('order_id', $contracts->pluck('order_id')->filter())
            ->where('direction', 'receivable')->pluck('id', 'order_id');
        $contracts->each(function ($contract) use ($contractDebtIds) {
            $contract->debt_id = $contractDebtIds[$contract->order_id] ?? null;
        });
        $paymentSchedules = app(PaymentScheduleWorkflow::class)->summariesForDebts(
            $debts->pluck('id')->merge($contractDebtIds->values())->unique()->all()
        );

        $purchasesQuery = DB::table('purchase_orders')->orderByDesc('id');
        $purchases = $section === 'purchases'
            ? $purchasesQuery->paginate(30, ['*'], 'purchases_page')->withQueryString()
            : $purchasesQuery->limit(30)->get();
        $customerCreditsQuery = DB::table('customer_credits')->join('users', 'users.id', '=', 'customer_credits.customer_id')
            ->select('customer_credits.*', 'users.name as customer_name')->orderByDesc('customer_credits.id');
        $customerCredits = $section === 'finance'
            ? $customerCreditsQuery->paginate(30, ['*'], 'credits_page')->withQueryString()
            : $customerCreditsQuery->limit(30)->get();
        $supplierIncidentsQuery = DB::table('supplier_incidents')->join('purchase_items', 'purchase_items.id', '=', 'supplier_incidents.purchase_item_id')
            ->join('products', 'products.id', '=', 'purchase_items.product_id')
            ->select('supplier_incidents.*', 'products.name as product_name')->orderByDesc('supplier_incidents.id');
        $supplierIncidents = $section === 'purchases'
            ? $supplierIncidentsQuery->paginate(30, ['*'], 'incidents_page')->withQueryString()
            : $supplierIncidentsQuery->limit(30)->get();
        $ordersQuery = DB::table('orders')->orderByDesc('id');
        $orders = $section === 'orders' ? $ordersQuery->paginate(30, ['*'], 'orders_page')->withQueryString() : $ordersQuery->limit(30)->get();
        $shipmentsQuery = DB::table('shipments')->join('orders', 'orders.id', '=', 'shipments.order_id')
            ->select('shipments.*', 'orders.delivery_address')->orderByDesc('shipments.id');
        $shipments = $section === 'orders' ? $shipmentsQuery->paginate(30, ['*'], 'shipments_page')->withQueryString() : $shipmentsQuery->limit(30)->get();
        $deliveryRunsQuery = DB::table('delivery_runs')->orderByDesc('id');
        $deliveryRuns = $section === 'orders' ? $deliveryRunsQuery->paginate(30, ['*'], 'runs_page')->withQueryString() : $deliveryRunsQuery->limit(30)->get();
        $returnsQuery = DB::table('returns')->orderByDesc('id');
        $returns = $section === 'returns' ? $returnsQuery->paginate(30, ['*'], 'returns_page')->withQueryString() : $returnsQuery->limit(30)->get();
        $stocktakesQuery = DB::table('stock_documents')->where('type', 'stocktake')->orderByDesc('id');
        $stocktakes = $section === 'inventory' && $request->query('view') === 'stocktakes'
            ? $stocktakesQuery->paginate(30, ['*'], 'stocktakes_page')->withQueryString()
            : $stocktakesQuery->limit(30)->get();
        $stockMovementQuery = DB::table('stock_movements')->join('stock_documents', 'stock_documents.id', '=', 'stock_movements.stock_document_id')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
            ->select('stock_movements.*', 'stock_documents.code as document_code', 'stock_documents.type as document_type',
                'products.name as product_name', 'warehouses.name as warehouse_name')->orderByDesc('stock_movements.id');
        $stockMovements = $section === 'inventory' && $request->query('view') === 'movements'
            ? $stockMovementQuery->paginate(100, ['*'], 'movements_page')->withQueryString()
            : $stockMovementQuery->limit(100)->get();

        return view('staff.dashboard', [
            'activeSection' => 'overview',
            'salesOrders' => $salesOrders,
            'pendingSalesOrderCount' => DB::table('orders')->where('status', 'awaiting_confirmation')->count(),
            'salesOrderItems' => DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
                ->whereIn('order_items.order_id', $salesOrders->pluck('id'))
                ->select('order_items.*', 'products.name as product_name', 'products.unit')->get()->groupBy('order_id'),
            'quotes' => $quotes,
            'quoteItems' => DB::table('quote_items')->join('products', 'products.id', '=', 'quote_items.product_id')
                ->leftJoinSub(DB::table('inventory')->select('product_id')->selectRaw('SUM(quantity) as available')->groupBy('product_id'),
                    'stock', 'stock.product_id', '=', 'quote_items.product_id')
                ->whereIn('quote_items.quote_id', $quotes->pluck('id'))
                ->select('quote_items.*', 'products.name as product_name')->selectRaw('COALESCE(stock.available, 0) as available')
                ->get()->groupBy('quote_id'),
            'purchaseItems' => DB::table('purchase_items')->join('products', 'products.id', '=', 'purchase_items.product_id')
                ->whereIn('purchase_items.purchase_order_id', $purchases->pluck('id'))
                ->select('purchase_items.*', 'products.name as product_name')->get()->groupBy('purchase_order_id'),
            'contracts' => $contracts,
            'contractItems' => DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
                ->whereIn('order_items.order_id', $contracts->pluck('order_id')->filter())
                ->select('order_items.*', 'products.name as product_name', 'products.unit', 'products.specification')
                ->get()->groupBy('order_id'),
            'orders' => $orders,
            'staffAccountCount' => DB::table('users')->where('role', '!=', 'customer')->count(),
            'invoices' => DB::table('invoices')->get()->keyBy('order_id'),
            'debts' => $debts,
            'paymentSchedules' => $paymentSchedules,
            'customerCredits' => $customerCredits,
            'creditUsed' => DB::table('customer_credit_movements')->select('customer_credit_id')
                ->selectRaw('SUM(amount) as total')->groupBy('customer_credit_id')->pluck('total', 'customer_credit_id'),
            'customerDebts' => DB::table('debts')->leftJoin('orders', 'orders.id', '=', 'debts.order_id')
                ->leftJoin('contracts', 'contracts.id', '=', 'orders.contract_id')
                ->where('debts.direction', 'receivable')->where('debts.status', 'open')
                ->where(fn ($query) => $query->whereNull('orders.id')->orWhere(function ($orderQuery) {
                    $orderQuery->whereNotIn('orders.status', ['awaiting_confirmation', 'awaiting_contract', 'rejected', 'cancelled'])
                        ->where(fn ($contractQuery) => $contractQuery->where('orders.requires_contract', false)
                            ->orWhere('contracts.status', 'signed'));
                }))->select('debts.*')->get(),
            'purchases' => $purchases,
            'supplierIncidents' => $supplierIncidents,
            'shipments' => $shipments,
            'deliveryRuns' => $deliveryRuns,
            'returns' => $returns,
            'supplierReturns' => DB::table('supplier_returns')
                ->leftJoin('users as responsibility_confirmers', 'responsibility_confirmers.id', '=', 'supplier_returns.responsibility_confirmed_by')
                ->select('supplier_returns.*', 'responsibility_confirmers.name as responsibility_confirmer_name')
                ->get()->keyBy('return_id'),
            'approvalHistory' => in_array($request->user()->role, ['manager', 'admin'], true)
                ? DB::table('approval_events')->join('users', 'users.id', '=', 'approval_events.actor_id')
                    ->select('approval_events.*', 'users.name as actor_name')->orderByDesc('decided_at')->limit(20)->get()
                : collect(),
            'eligibleReturnSuppliers' => DB::table('purchase_orders')
                ->join('purchase_items', 'purchase_items.purchase_order_id', '=', 'purchase_orders.id')
                ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
                ->where('purchase_items.received_quantity', '>', 0)->where('suppliers.active', true)
                ->select('purchase_items.product_id', 'suppliers.id', 'suppliers.name')->distinct()->get()->groupBy('product_id'),
            'supplierPayables' => DB::table('debts')->where('direction', 'payable')->where('status', 'open')->get(),
            'products' => DB::table('products')->orderBy('name')->get(),
            'suppliers' => DB::table('suppliers')->where('active', true)->get(),
            'warehouses' => DB::table('warehouses')->where('active', true)->get(),
            'stocktakes' => $stocktakes,
            'stocktakeItems' => DB::table('stock_document_items')->join('stock_documents', 'stock_documents.id', '=', 'stock_document_items.stock_document_id')
                ->join('products', 'products.id', '=', 'stock_document_items.product_id')->whereIn('stock_documents.id', $stocktakes->pluck('id'))
                ->select('stock_document_items.*', 'products.name as product_name')->get()->keyBy('stock_document_id'),
            'inventory' => DB::table('inventory')->join('products', 'products.id', '=', 'inventory.product_id')
                ->join('warehouses', 'warehouses.id', '=', 'inventory.warehouse_id')
                ->select('inventory.*', 'products.name as product_name', 'products.minimum_stock', 'warehouses.name as warehouse_name')->get(),
            'stockMovements' => $stockMovements,
        ]);
    }

    public function approvalHistory(Request $request): View
    {
        abort_unless(in_array($request->user()->role, ['manager', 'admin'], true), 403);
        $approvalHistory = DB::table('approval_events')->join('users', 'users.id', '=', 'approval_events.actor_id')
            ->select('approval_events.*', 'users.name as actor_name')
            ->orderByDesc('decided_at')->orderByDesc('approval_events.id')->paginate(20)->withQueryString();

        return view('staff.approval-history', compact('approvalHistory'));
    }

    public function section(Request $request, string $section): View
    {
        $permissions = [
            'quotes' => ['quotes.view'], 'contracts' => ['contracts.view'],
            'sales_orders' => ['sales.orders.view'], 'finance' => ['finance.view'],
            'purchases' => ['purchases.view'], 'orders' => ['orders.view'],
            'returns' => ['returns.view'], 'inventory' => ['inventory.view'],
        ];
        abort_unless(isset($permissions[$section]), 404);
        abort_unless(collect($permissions[$section])->contains(fn (string $permission) => $request->user()->hasPermission($permission)), 403);

        return $this->dashboard($request)->with('activeSection', $section);
    }

    public function reviewOrder(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:confirm,reject'], 'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000']]);
        $workflow->reviewOrder($id, $request->user()->id, $data['decision'] === 'confirm', $data['reason'] ?? null);
        $order = DB::table('orders')->select('code', 'status')->find($id);

        return back()->with('success', 'Đơn '.$order->code.' đã '.($data['decision'] === 'confirm' ? 'được xác nhận' : 'bị từ chối').'; trạng thái: '.UiFormat::status($order->status).'.');
    }

    public function sendQuote(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'prices' => ['nullable', 'array'], 'prices.*' => ['numeric', 'min:0'],
            'estimated_available_on' => ['nullable', 'date', 'after_or_equal:today'],
            'alternative_material' => ['nullable', 'string', 'max:1000'],
        ]);
        $workflow->sendQuote($id, $request->user()->id, $data['prices'] ?? [], $data['valid_until'],
            $data['estimated_available_on'] ?? null, $data['alternative_material'] ?? null);

        $message = DB::table('quotes')->where('id', $id)->value('status') === 'pending_discount_approval'
            ? 'Đã trình duyệt chiết khấu.' : 'Đã gửi báo giá cho khách hàng.';

        return back()->with('success', $message);
    }

    public function rejectQuote(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $changed = DB::table('quotes')->where('id', $id)->whereIn('status', ['requested', 'draft'])
            ->update(['status' => 'rejected', 'staff_note' => $data['reason'], 'updated_at' => now()]);
        abort_unless($changed, 422);

        return back()->with('success', 'Đã từ chối yêu cầu báo giá.');
    }

    public function reviewQuoteDiscount(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000'],
        ]);
        $workflow->reviewQuoteDiscount($id, $request->user()->id, $data['decision'] === 'approve', $data['note'] ?? null);

        return back()->with('success', 'Đã xử lý đề xuất chiết khấu.');
    }

    public function createContract(Request $request, CommerceWorkflow $workflow, int $orderId): RedirectResponse
    {
        $data = $request->validate([
            'deposit_required' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'min:0'],
            'payment_terms' => ['required', 'string', 'max:5000'],
            'delivery_terms' => ['required', 'string', 'max:5000'],
            'installments' => ['required', 'array', 'min:1'],
            'installments.*.sequence' => ['required', 'integer', 'min:1', 'distinct'],
            'installments.*.label' => ['required', 'string', 'max:150'],
            'installments.*.amount' => ['required', 'numeric', 'gt:0'],
            'installments.*.trigger' => ['required', 'in:on_signing,before_issue,after_handover,fixed_date'],
            'installments.*.due_on' => ['nullable', 'date'],
            'installments.*.days_after_trigger' => ['nullable', 'integer', 'min:0'],
            'installments.*.required_before_issue' => ['sometimes', 'boolean'],
        ]);
        $id = $workflow->createContract($orderId, $request->user()->id,
            (string) $data['deposit_required'], $data['payment_terms'], $data['delivery_terms'], $data['installments']);

        return back()->with('success', 'Đã lập hợp đồng '.DB::table('contracts')->find($id)->code.'.');
    }

    public function updateContract(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'deposit_required' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'min:0'],
            'payment_terms' => ['required', 'string', 'max:5000'],
            'delivery_terms' => ['required', 'string', 'max:5000'],
            'installments' => ['required', 'array', 'min:1'],
            'installments.*.sequence' => ['required', 'integer', 'min:1', 'distinct'],
            'installments.*.label' => ['required', 'string', 'max:150'],
            'installments.*.amount' => ['required', 'numeric', 'gt:0'],
            'installments.*.trigger' => ['required', 'in:on_signing,before_issue,after_handover,fixed_date'],
            'installments.*.due_on' => ['nullable', 'date'],
            'installments.*.days_after_trigger' => ['nullable', 'integer', 'min:0'],
            'installments.*.required_before_issue' => ['sometimes', 'boolean'],
        ]);
        $contract = DB::table('contracts')->where('id', $id)->firstOrFail();
        abort_if(bccomp((string) $data['deposit_required'], (string) $contract->total, 2) > 0, 422);
        DB::transaction(function () use ($request, $id, $data, $contract) {
            $changed = DB::table('contracts')->where('id', $id)->whereIn('status', ['draft', 'changes_requested'])->update([
                'deposit_required' => $data['deposit_required'], 'payment_terms' => $data['payment_terms'],
                'delivery_terms' => $data['delivery_terms'], 'last_edited_by' => $request->user()->id,
                'last_submitted_by' => null, 'updated_at' => now(),
            ]);
            abort_unless($changed, 422);
            if ($contract->order_id) {
                $debtId = DB::table('debts')->where('order_id', $contract->order_id)
                    ->where('direction', 'receivable')->value('id');
                if ($debtId) {
                    app(PaymentScheduleWorkflow::class)->replaceDraftSchedule(
                        (int) $debtId, $data['installments'], $request->user()->id
                    );
                } else {
                    app(PaymentScheduleWorkflow::class)->saveContractDraftSchedule(
                        $id, $data['installments'], $request->user()->id, (string) $data['deposit_required']
                    );
                }
            } else {
                app(PaymentScheduleWorkflow::class)->saveContractDraftSchedule(
                    $id, $data['installments'], $request->user()->id, (string) $data['deposit_required']
                );
            }
        });

        return back()->with('success', 'Đã sửa hợp đồng.');
    }

    public function submitContract(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $workflow->submitContract($id, $request->user()->id);

        return back()->with('success', 'Đã trình hợp đồng lên Ban quản lý.');
    }

    public function reviewContract(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,revise'],
            'note' => ['required_if:decision,revise', 'nullable', 'string', 'max:2000'],
        ]);
        $workflow->reviewContract($id, $request->user()->id, $data['decision'] === 'approve', $data['note'] ?? null);

        return back()->with('success', 'Đã xử lý phê duyệt hợp đồng.');
    }

    public function downloadContractPdf(int $id): StreamedResponse
    {
        $contract = DB::table('contracts')->where('id', $id)->firstOrFail();
        abort_unless(in_array($contract->status, ['approved', 'signed'], true) && $contract->customer_confirmed_at, 409, 'Hợp đồng cần được quản lý duyệt và khách hàng xác nhận trước khi xuất.');
        $order = $contract->order_id ? DB::table('orders')->find($contract->order_id) : null;
        $quote = DB::table('quotes')->find($contract->quote_id);
        $customer = DB::table('users')->find($order?->customer_id ?? $quote?->customer_id);
        abort_unless($customer, 404);
        $items = DB::table($order ? 'order_items' : 'quote_items')
            ->join('products', 'products.id', '=', 'product_id')
            ->where($order ? 'order_id' : 'quote_id', $order?->id ?? $quote?->id)
            ->select('products.name', 'products.unit', 'products.specification', 'quantity', 'unit_price', 'line_total')->get();
        $pdf = new Dompdf;
        $pdf->loadHtml(view('staff.contract-pdf', compact('contract', 'order', 'quote', 'customer', 'items'))->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return response()->streamDownload(static function () use ($pdf): void {
            echo $pdf->output();
        }, $contract->code.'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    public function signContract(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $request->validate(['document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $path = $request->file('document')->store('contracts', 'local');
        try {
            $workflow->signContract($id, $path, $request->user()->id);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('success', 'Đã ghi nhận hợp đồng ký. Đơn hàng chuyển sang bước tài chính.');
    }

    public function recordPayment(Request $request, CommerceWorkflow $workflow, int $debtId): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gt:0'],
            'method' => ['required', 'in:cash,bank_transfer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $workflow->recordPayment($debtId, $request->user()->id, (string) $data['amount'], $data['method'],
            $data['reference'] ?? null, $data['idempotency_key']);

        return back()->with('success', 'Đã ghi nhận thanh toán.');
    }

    public function issueInvoice(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $workflow->issueInvoice($id, $request->user()->id);

        return back()->with('success', 'Đã lập hóa đơn và phát lệnh xuất hàng cho kho.');
    }

    public function scheduleDebt(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'due_on' => ['required', 'date', 'after_or_equal:today'],
            'settlement_note' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->scheduleDebt($id, $data['due_on'], $data['settlement_note']);

        return back()->with('success', 'Đã ghi lịch quyết toán công nợ.');
    }

    public function useCustomerCredit(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:refund,offset'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'debt_id' => ['nullable', 'integer', 'exists:debts,id'],
            'method' => ['nullable', 'in:cash,bank_transfer'],
            'reference' => ['nullable', 'string', 'max:150'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $workflow->useCustomerCredit($id, $request->user()->id, (string) $data['amount'], $data['type'],
            $data['debt_id'] ?? null, $data['method'] ?? null, $data['reference'] ?? null, $data['idempotency_key']);

        return back()->with('success', 'Đã ghi nhận hoàn tiền hoặc cấn trừ cho khách hàng.');
    }

    public function settleSupplierReturn(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'debt_id' => ['required', 'exists:debts,id'],
            'credit_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'settlement_note' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->settleSupplierReturn($id, $data['debt_id'], $request->user()->id,
            (string) $data['credit_amount'], $data['settlement_note']);

        return back()->with('success', 'Đã bù trừ công nợ nhà cung cấp theo xác nhận.');
    }

    public function cancelContract(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $workflow->cancelContract($id, $request->user()->id, $data['reason']);

        return back()->with('success', 'Đã hủy hợp đồng trước khi ký.');
    }

    public function createPurchase(Request $request, FulfillmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'], 'warehouse_id' => ['required', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        $workflow->createPurchaseOrder($data['supplier_id'], $data['warehouse_id'], $request->user()->id, $data['items']);

        return back()->with('success', 'Đã lập đề xuất nhập hàng.');
    }

    public function approvePurchase(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $workflow->approvePurchaseOrder($id, $request->user()->id);

        return back()->with('success', 'Đã duyệt đề xuất nhập hàng.');
    }

    public function receivePurchase(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['received' => ['required', 'array'], 'received.*' => ['numeric', 'min:0']]);
        $workflow->receivePurchaseOrder($id, $request->user()->id, $data['received']);

        return back()->with('success', 'Đã nhập kho theo số lượng thực nhận.');
    }

    public function reportSupplierIncident(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'purchase_item_id' => ['required', 'integer'],
            'type' => ['required', 'in:shortage,damaged'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->reportSupplierIncident($id, $data['purchase_item_id'], $request->user()->id,
            $data['type'], (string) $data['quantity'], $data['note']);

        return back()->with('success', 'Đã ghi nhận sự vụ với nhà cung cấp.');
    }

    public function resolveSupplierIncident(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['resolution_note' => ['required', 'string', 'max:2000']]);
        $workflow->resolveSupplierIncident($id, $request->user()->id, $data['resolution_note']);

        return back()->with('success', 'Đã ghi kết quả xử lý sự vụ nhà cung cấp.');
    }

    public function issueOrder(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['warehouse_id' => ['required', 'exists:warehouses,id']]);
        $workflow->issueOrder($id, $data['warehouse_id'], $request->user()->id);

        return back()->with('success', 'Đã xuất kho. Tồn kho thực tế được cập nhật.');
    }

    public function planShipment(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'carrier_name' => ['required', 'string', 'max:150'], 'vehicle' => ['required', 'string', 'max:100'],
            'route_note' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->planShipment($id, null, $data['carrier_name'], $data['vehicle'], $data['route_note']);

        return back()->with('success', 'Đã lập chuyến giao hàng.');
    }

    public function groupDeliveryRun(Request $request, FulfillmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:2'], 'shipment_ids.*' => ['required', 'integer', 'distinct'],
            'carrier_name' => ['required', 'string', 'max:150'], 'vehicle' => ['required', 'string', 'max:100'],
            'route_note' => ['required', 'string', 'max:2000'],
            'capacity_kg' => ['required', 'numeric', 'gt:0'],
            'capacity_m3' => ['nullable', 'numeric', 'gt:0'],
            'stop_order' => ['required', 'array'], 'stop_order.*' => ['integer', 'min:1'],
            'optimize_route' => ['nullable', 'boolean'],
            'origin_lat' => ['required_if:optimize_route,1', 'nullable', 'numeric', 'between:-90,90'],
            'origin_lon' => ['required_if:optimize_route,1', 'nullable', 'numeric', 'between:-180,180'],
            'stop_coordinates' => ['nullable', 'array'],
            'stop_coordinates.*.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'stop_coordinates.*.lon' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $origin = ! empty($data['optimize_route']) ? ['lat' => $data['origin_lat'], 'lon' => $data['origin_lon']] : null;
        $workflow->groupDeliveryRun($data['shipment_ids'], $request->user()->id,
            $data['carrier_name'], $data['vehicle'], $data['route_note'], (string) $data['capacity_kg'],
            $data['stop_order'], $origin, $data['stop_coordinates'] ?? [], isset($data['capacity_m3']) ? (string) $data['capacity_m3'] : null);

        return back()->with('success', 'Đã gom các đơn vào một lộ trình giao hàng.');
    }

    public function handoverPickup(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['recipient' => ['required', 'string', 'max:150']]);
        $workflow->handoverPickup($id, $request->user()->id, $data['recipient']);

        return back()->with('success', 'Đã bàn giao hàng tại kho cho khách.');
    }

    public function pickupShipment(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['receiver' => ['required', 'string', 'max:150']]);
        $workflow->pickupShipment($id, $request->user()->id, $data['receiver']);

        return back()->with('success', 'Đã ghi nhận đơn vị vận chuyển nhận hàng.');
    }

    public function rescheduleShipment(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'vehicle' => ['required', 'string', 'max:100'],
            'route_note' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->rescheduleShipment($id, $data['vehicle'], $data['route_note']);

        return back()->with('success', 'Đã lên lịch giao lại sau sự cố.');
    }

    public function handover(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'string', 'max:150'], 'accepted' => ['required', 'boolean'],
            'condition_note' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->handover($id, $data['recipient'], (bool) $data['accepted'], $data['condition_note']);

        return back()->with('success', 'Đã ghi nhận bàn giao.');
    }

    public function inspectReturn(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'], 'reusable' => ['required', 'boolean'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->inspectReturn($id, $data['warehouse_id'], $request->user()->id, (bool) $data['reusable'], $data['note']);

        return back()->with('success', 'Kho đã kiểm tra hàng trả.');
    }

    public function collectReturn(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'handed_over_by' => ['required', 'string', 'max:150'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->collectReturn($id, $request->user()->id, $data['handed_over_by'], (string) $data['quantity'], $data['note']);

        return back()->with('success', 'Đã ghi biên bản thu hồi hàng trả.');
    }

    public function sendDamagedReturnToSupplier(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'note' => ['required', 'string', 'max:2000'],
            'responsibility_confirmed' => ['required', 'accepted'],
            'responsibility_evidence' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->sendDamagedReturnToSupplier($id, $data['supplier_id'], $request->user()->id,
            $data['note'], true, $data['responsibility_evidence']);

        return back()->with('success', 'Đã ghi nhận trả hàng hỏng cho nhà cung cấp.');
    }

    public function issueExchange(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'carrier_name' => ['required', 'string', 'max:150'],
            'vehicle' => ['required', 'string', 'max:100'],
            'route_note' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->issueExchange($id, $data['warehouse_id'], $request->user()->id,
            $data['carrier_name'], $data['vehicle'], $data['route_note']);

        return back()->with('success', 'Đã xuất hàng thay thế và lập chuyến giao.');
    }

    public function requestExchangeRestock(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        $workflow->requestExchangeRestock($id, $data['supplier_id'], $request->user()->id, (string) $data['unit_price']);

        return back()->with('success', 'Đã lập đề xuất nhập hàng để đổi cho khách. Dự kiến có hàng sau 14 ngày; cần Ban quản lý duyệt và kho xác nhận thực nhập.');
    }

    public function settleReturn(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $workflow->settleReturn($id, $request->user()->id);

        return back()->with('success', 'Đã điều chỉnh công nợ theo hàng trả.');
    }

    public function proposeStocktake(Request $request, FulfillmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'], 'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->proposeStocktake($data['warehouse_id'], $data['product_id'], $request->user()->id,
            (string) $data['quantity'], $data['reason']);

        return back()->with('success', 'Đã trình biên bản kiểm kê để quản lý duyệt.');
    }

    public function reviewStocktake(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $workflow->reviewStocktake($id, $request->user()->id, $data['decision'] === 'approve');

        return back()->with('success', 'Đã xử lý biên bản kiểm kê.');
    }
}
