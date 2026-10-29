<?php

namespace App\Http\Controllers;

use App\Services\CommerceWorkflow;
use App\Services\FulfillmentWorkflow;
use App\Services\PaymentScheduleWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function home(): View
    {
        $categoryImages = ['XI-MANG' => 'mat-cement', 'GACH' => 'mat-brick', 'THEP' => 'mat-steel', 'CAT-DA' => 'mat-sand'];

        return view('store.home', [
            'featured' => $this->withStorefrontStock(DB::table('products')->where('active', true)->orderBy('id')->limit(4)->get()),
            'categories' => DB::table('categories')->where('active', true)->whereIn('code', array_keys($categoryImages))
                ->get()->sortBy(fn ($category) => array_search($category->code, array_keys($categoryImages), true)),
            'categoryImages' => $categoryImages,
            'productCounts' => DB::table('products')->where('active', true)->select('category_id')
                ->selectRaw('COUNT(*) as total')->groupBy('category_id')->pluck('total', 'category_id'),
        ]);
    }

    public function catalog(Request $request): View
    {
        $products = DB::table('products')->where('products.active', true)
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->select('products.*', 'categories.name as category_name');
        if ($request->filled('category')) {
            $products->where('categories.code', $request->query('category'));
        }
        if ($request->filled('q')) {
            $q = trim($request->query('q'));
            $products->where(fn ($query) => $query->where('products.name', 'like', "%{$q}%")
                ->orWhere('products.code', 'like', "%{$q}%"));
        }
        match ($request->query('sort')) {
            'price_asc' => $products->orderBy('products.price'),
            'price_desc' => $products->orderByDesc('products.price'),
            default => $products->orderBy('products.id'),
        };

        $page = $products->paginate(15)->withQueryString();
        $this->withStorefrontStock($page->getCollection());

        return view('store.catalog', [
            'products' => $page,
            'categories' => DB::table('categories')->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function product(int $id): View
    {
        $product = DB::table('products')->where('active', true)->where('id', $id)->firstOrFail();
        $category = DB::table('categories')->where('id', $product->category_id)->first(['name', 'code']);

        $related = $product->category_id
            ? DB::table('products')->where('active', true)->where('category_id', $product->category_id)
                ->where('id', '!=', $product->id)->orderBy('id')->limit(4)->get()
            : collect();
        $this->withStorefrontStock(collect([$product])->concat($related));

        return view('store.product', [
            'product' => $product,
            'categoryName' => $category?->name ?: 'Vật liệu xây dựng',
            'categoryCode' => $category?->code,
            'related' => $related,
        ]);
    }

    private function withStorefrontStock(Collection $products): Collection
    {
        if ($products->isEmpty()) {
            return $products;
        }

        $ids = $products->pluck('id');
        $warehouses = DB::table('warehouses')->where('active', true)->orderBy('name')->get(['id', 'name']);
        $stock = DB::table('inventory')->whereIn('product_id', $ids)->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->get(['product_id', 'warehouse_id', 'quantity'])->keyBy(fn ($row) => $row->product_id.'-'.$row->warehouse_id);
        $reserved = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.product_id', $ids)->whereIn('orders.pickup_warehouse_id', $warehouses->pluck('id'))
            ->whereIn('orders.status', ['awaiting_contract', 'awaiting_payment', 'ready_to_issue'])
            ->select('order_items.product_id', 'orders.pickup_warehouse_id')
            ->selectRaw('SUM(order_items.quantity) as quantity')
            ->groupBy('order_items.product_id', 'orders.pickup_warehouse_id')
            ->get()->keyBy(fn ($row) => $row->product_id.'-'.$row->pickup_warehouse_id);

        foreach ($products as $product) {
            $product->stock_by_warehouse = $warehouses->map(function ($warehouse) use ($product, $stock, $reserved) {
                $key = $product->id.'-'.$warehouse->id;
                $available = bcsub((string) ($stock[$key]->quantity ?? 0), (string) ($reserved[$key]->quantity ?? 0), 3);

                return ['name' => $warehouse->name, 'quantity' => bccomp($available, '0', 3) > 0 ? $available : '0'];
            });
            $product->has_stock = $product->stock_by_warehouse->contains(fn ($warehouse) => bccomp($warehouse['quantity'], '0', 3) > 0);
            $product->available_stock = $product->stock_by_warehouse->reduce(
                fn ($total, $warehouse) => bcadd($total, $warehouse['quantity'], 3), '0'
            );
        }

        return $products;
    }

    public function cart(Request $request): View
    {
        $cart = $request->session()->get('cart', []);
        $products = DB::table('products')->whereIn('id', array_keys($cart))->get();
        $total = '0.00';
        foreach ($products as $product) {
            $total = bcadd($total, bcmul((string) $product->price, (string) $cart[$product->id], 2), 2);
        }

        $warehouses = DB::table('warehouses')->where('active', true)->orderBy('name')->get();
        $canPickup = $products->every(fn ($product) => $product->direct_purchase_allowed);
        $availability = [];
        $stock = DB::table('inventory')->whereIn('product_id', $products->pluck('id'))
            ->whereIn('warehouse_id', $warehouses->pluck('id'))->get()
            ->keyBy(fn ($row) => $row->warehouse_id.':'.$row->product_id);
        $reserved = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.product_id', $products->pluck('id'))
            ->whereIn('orders.pickup_warehouse_id', $warehouses->pluck('id'))
            ->whereIn('orders.status', ['awaiting_contract', 'awaiting_payment', 'ready_to_issue'])
            ->select('orders.pickup_warehouse_id', 'order_items.product_id')
            ->selectRaw('SUM(order_items.quantity) as quantity')
            ->groupBy('orders.pickup_warehouse_id', 'order_items.product_id')->get()
            ->keyBy(fn ($row) => $row->pickup_warehouse_id.':'.$row->product_id);
        foreach ($warehouses as $warehouse) {
            foreach ($products as $product) {
                $key = $warehouse->id.':'.$product->id;
                $available = bcsub((string) ($stock[$key]->quantity ?? 0), (string) ($reserved[$key]->quantity ?? 0), 3);
                $availability[$warehouse->id][$product->id] = max(0, (float) $available);
            }
        }

        $pickupOrderKey = $request->session()->get('pickup_order_key');
        if (! $pickupOrderKey) {
            $pickupOrderKey = (string) Str::uuid();
            $request->session()->put('pickup_order_key', $pickupOrderKey);
        }

        return view('store.cart', compact('cart', 'products', 'total', 'warehouses', 'canPickup', 'availability', 'pickupOrderKey'));
    }

    public function addToCart(Request $request): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id'], 'quantity' => ['required', 'regex:/^\d+(?:\.\d{1,3})?$/', 'numeric', 'gt:0', 'lte:1000000'], 'replace' => ['sometimes', 'boolean']]);
        $product = DB::table('products')->where('id', $data['product_id'])->where('active', true)->first(['id', 'name', 'unit', 'image_path']);
        abort_unless($product, 404);
        $cart = $request->session()->get('cart', []);
        $id = (int) $data['product_id'];
        $replaced = (bool) ($data['replace'] ?? false);
        $quantity = $replaced ? $data['quantity'] : min(1000000, ($cart[$id] ?? 0) + $data['quantity']);
        $this->validateQuantityUnit($quantity, $product->unit);
        $cart[$id] = $quantity;
        $request->session()->put('cart', $cart);
        $request->session()->put('pickup_order_key', (string) Str::uuid());

        return ($request->input('next') === 'cart' ? redirect()->route('cart') : back())
            ->with('cart_added', ['name' => $product->name, 'unit' => $product->unit, 'image' => $product->image_path, 'quantity' => $quantity, 'replaced' => $replaced]);
    }

    public function updateCart(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'quantity' => ['required', 'regex:/^\d+(?:\.\d{1,3})?$/', 'numeric', 'min:0', 'lte:1000000'], 'action' => ['sometimes', 'in:increase,decrease,remove']]);
        $cart = $request->session()->get('cart', []);
        $product = DB::table('products')->where('id', $data['product_id'])->where('active', true)->first(['id', 'unit']);
        if (($data['action'] ?? null) === 'remove' || $data['quantity'] == 0 && ! isset($data['action'])) {
            unset($cart[$data['product_id']]);
        } elseif ($product) {
            $step = $product->unit === 'm³' ? '0.1' : '1';
            $quantity = match ($data['action'] ?? null) {
                'increase' => rtrim(rtrim(bcadd((string) $data['quantity'], $step, 3), '0'), '.'),
                'decrease' => max(in_array($product->unit, ['m³', 'kg'], true) ? 0.001 : 1,
                    (float) bcsub((string) $data['quantity'], $step, 3)),
                default => $data['quantity'],
            };
            if (bccomp((string) $quantity, '1000000', 3) > 0) {
                throw ValidationException::withMessages(['quantity' => 'Số lượng không được vượt 1.000.000.']);
            }
            $this->validateQuantityUnit($quantity, $product->unit);
            $cart[$data['product_id']] = $quantity;
        }
        $request->session()->put('cart', $cart);
        $pickupOrderKey = (string) Str::uuid();
        $request->session()->put('pickup_order_key', $pickupOrderKey);

        if ($request->expectsJson()) {
            $prices = DB::table('products')->whereIn('id', array_keys($cart))->pluck('price', 'id');
            $total = '0.00';
            foreach ($prices as $id => $price) {
                $total = bcadd($total, bcmul((string) $price, (string) $cart[$id], 2), 2);
            }

            return response()->json([
                'quantity' => isset($cart[$data['product_id']]) ? (string) $cart[$data['product_id']] : null,
                'lineTotal' => isset($cart[$data['product_id']], $prices[$data['product_id']])
                    ? number_format((float) bcmul((string) $prices[$data['product_id']], (string) $cart[$data['product_id']], 2), 0, ',', '.').' ₫' : null,
                'total' => number_format((float) $total, 0, ',', '.').' ₫',
                'canDecrease' => isset($cart[$data['product_id']]) && $product
                    && (float) $cart[$data['product_id']] > (in_array($product->unit, ['m³', 'kg'], true) ? 0.001 : 1),
                'removed' => ! isset($cart[$data['product_id']]),
                'idempotencyKey' => $pickupOrderKey,
            ]);
        }

        return redirect()->route('cart');
    }

    private function validateQuantityUnit(int|float|string $quantity, string $unit): void
    {
        if (! in_array($unit, ['m³', 'kg'], true) && floor((float) $quantity) !== (float) $quantity) {
            throw ValidationException::withMessages(['quantity' => 'Vật liệu tính theo '.$unit.' cần số lượng nguyên.']);
        }
    }

    public function requestQuote(Request $request, CommerceWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'delivery_address' => ['required', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:2000'],
            'cart_key' => ['sometimes', 'nullable', 'uuid'], 'quantities' => ['sometimes', 'array', 'min:1'],
            'quantities.*' => ['required', 'numeric', 'gt:0', 'lte:1000000'],
        ]);
        $cart = $data['quantities'] ?? $request->session()->get('cart', []);
        $id = $workflow->requestQuote($request->user()->id, $cart, $data['delivery_address'], $data['note'] ?? null);
        $currentCartKey = $request->session()->get('pickup_order_key');
        $submittedCartKey = $data['cart_key'] ?? null;
        if (! $currentCartKey || $submittedCartKey && hash_equals((string) $currentCartKey, $submittedCartKey)) {
            $request->session()->forget(['cart', 'pickup_order_key']);
        }

        return redirect()->route('my.quotes')->with('success', 'Đã gửi yêu cầu báo giá '.DB::table('quotes')->find($id)->code.'.');
    }

    public function placePickupOrder(Request $request, CommerceWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'idempotency_key' => ['sometimes', 'nullable', 'uuid'],
            'quantities' => ['sometimes', 'array', 'min:1'],
            'quantities.*' => ['required', 'numeric', 'gt:0', 'lte:1000000'],
        ]);
        $items = $data['quantities'] ?? $request->session()->get('cart', []);
        $currentCartKey = $request->session()->get('pickup_order_key');
        if ($currentCartKey && empty($data['idempotency_key'])) {
            throw ValidationException::withMessages(['workflow' => 'Giỏ hàng đã thay đổi; vui lòng tải lại trang trước khi đặt hàng.']);
        }
        $idempotencyKey = $data['idempotency_key'] ?? $currentCartKey ?? (string) Str::uuid();
        $ownsCurrentCart = ! $currentCartKey || hash_equals((string) $currentCartKey, $idempotencyKey);
        if (! $currentCartKey) {
            $request->session()->put('pickup_order_key', $idempotencyKey);
        }
        $id = $workflow->placePickupOrder($request->user()->id, $items, $data['warehouse_id'], $idempotencyKey);
        if ($ownsCurrentCart) {
            $request->session()->forget(['cart', 'pickup_order_key']);
        }

        return redirect()->route('my.orders')->with('success', 'Đã đặt đơn '.DB::table('orders')->find($id)->code.'. Nhân viên Kinh doanh sẽ kiểm tra đơn và tồn kho; bạn thanh toán đủ sau khi đơn được xác nhận.');
    }

    public function cancelPickupOrder(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $workflow->cancelPickupOrder($id, $request->user()->id);

        return back()->with('success', 'Đã hủy đơn nhận tại kho.');
    }

    public function updatePickupOrder(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['quantities' => ['required', 'array'], 'quantities.*' => ['required', 'numeric', 'gt:0', 'lte:1000000']]);
        $workflow->updateUnpaidPickupOrder($id, $request->user()->id, $data['quantities']);

        return back()->with('success', 'Đã cập nhật số lượng trong đơn hàng.');
    }

    public function myQuotes(Request $request): View
    {
        $quotes = DB::table('quotes')->where('customer_id', $request->user()->id)->orderByDesc('id')->paginate(10);
        $items = DB::table('quote_items')->join('products', 'products.id', '=', 'quote_items.product_id')
            ->whereIn('quote_id', $quotes->pluck('id'))
            ->select('quote_items.quote_id', 'quote_items.product_id', 'quote_items.quantity', 'products.name', 'products.image_path')
            ->get()->groupBy('quote_id');

        return view('store.quotes', compact('quotes', 'items'));
    }

    public function showQuote(Request $request, int $id): View
    {
        $quote = DB::table('quotes')->where('customer_id', $request->user()->id)->where('id', $id)->firstOrFail();
        $items = DB::table('quote_items')->join('products', 'products.id', '=', 'quote_items.product_id')
            ->where('quote_id', $id)->select('quote_items.*', 'products.name', 'products.image_path', 'products.unit')->get();

        return view('store.quote', compact('quote', 'items'));
    }

    public function updateQuote(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'delivery_address' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'numeric', 'gt:0', 'lte:1000000'],
        ]);
        $workflow->updateRequestedQuote($id, $request->user()->id, $data['quantities'], $data['delivery_address'], $data['note'] ?? null);

        return back()->with('success', 'Đã cập nhật yêu cầu báo giá.');
    }

    public function withdrawQuote(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $workflow->withdrawRequestedQuote($id, $request->user()->id);

        return redirect()->route('my.quotes')->with('success', 'Đã rút yêu cầu báo giá. Lịch sử vẫn được lưu.');
    }

    public function acceptQuote(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $orderId = $workflow->acceptQuote($id, $request->user()->id);

        return redirect()->route('my.orders')->with('success', 'Đã tạo đơn '.DB::table('orders')->find($orderId)->code.' từ báo giá. Nhân viên Kinh doanh sẽ xác nhận đơn.');
    }

    public function myContracts(Request $request): View
    {
        $contracts = DB::table('contracts')->leftJoin('orders', 'orders.id', '=', 'contracts.order_id')
            ->leftJoin('quotes', 'quotes.id', '=', 'contracts.quote_id')
            ->where(fn ($query) => $query->where('orders.customer_id', $request->user()->id)
                ->orWhere('quotes.customer_id', $request->user()->id))
            ->whereIn('contracts.status', ['approved', 'changes_requested', 'signed', 'cancelled'])
            ->select('contracts.*', 'orders.code as order_code')->orderByDesc('contracts.id')->get();
        $contractItems = DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.order_id', $contracts->pluck('order_id')->filter())
            ->select('order_items.*', 'products.name as product_name', 'products.unit', 'products.specification')
            ->get()->groupBy('order_id');
        $contractDebtIds = DB::table('debts')->whereIn('order_id', $contracts->pluck('order_id')->filter())
            ->where('direction', 'receivable')->pluck('id', 'order_id');
        $contracts->each(function ($contract) use ($contractDebtIds) {
            $contract->debt_id = $contractDebtIds[$contract->order_id] ?? null;
        });
        $paymentSchedules = app(PaymentScheduleWorkflow::class)->summariesForDebts($contractDebtIds->values()->all());

        return view('store.contracts', compact('contracts', 'contractItems', 'paymentSchedules'));
    }

    public function respondToContract(Request $request, CommerceWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:accept,revise'], 'note' => ['nullable', 'string', 'max:2000']]);
        $workflow->respondToContract($id, $request->user()->id, $data['decision'] === 'accept', $data['note'] ?? null);

        return back()->with('success', $data['decision'] === 'accept' ? 'Đã xác nhận hợp đồng.' : 'Đã gửi yêu cầu sửa hợp đồng.');
    }

    public function myOrders(Request $request): View
    {
        $orders = DB::table('orders')->leftJoin('quotes', 'quotes.id', '=', 'orders.quote_id')
            ->where('orders.customer_id', $request->user()->id)
            ->select('orders.*', 'quotes.staff_note as quote_stock_note')->orderByDesc('orders.id')->get();
        $items = DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_id', $orders->pluck('id'))->select('order_items.*', 'products.name', 'products.image_path', 'products.unit')->get()->groupBy('order_id');
        $returns = DB::table('returns')->whereIn('order_id', $orders->pluck('id'))->get()->groupBy('order_id');
        $invoices = DB::table('invoices')->whereIn('order_id', $orders->pluck('id'))->get()->keyBy('order_id');

        return view('store.orders', compact('orders', 'items', 'returns', 'invoices'));
    }

    public function showInvoice(Request $request, int $id): View
    {
        $invoice = DB::table('invoices')->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->where('invoices.id', $id)->where('orders.customer_id', $request->user()->id)
            ->select('invoices.*', 'orders.code as order_code', 'orders.delivery_address')->firstOrFail();
        $items = DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('order_items.order_id', $invoice->order_id)
            ->select('order_items.*', 'products.name', 'products.unit')->get();

        return view('store.invoice', compact('invoice', 'items'));
    }

    public function requestReturn(Request $request, FulfillmentWorkflow $workflow, int $id): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'lte:1000000'],
            'reason' => ['required', 'string', 'max:2000'], 'type' => ['required', 'in:return,exchange'],
        ]);
        $workflow->requestReturn($id, $request->user()->id, $data['product_id'], (string) $data['quantity'], $data['reason'], $data['type']);

        return back()->with('success', 'Đã gửi yêu cầu '.($data['type'] === 'exchange' ? 'đổi' : 'trả').' hàng để kho kiểm tra.');
    }

    public function profile(Request $request): View
    {
        $debts = DB::table('debts')->join('orders', 'orders.id', '=', 'debts.order_id')
            ->leftJoin('contracts', 'contracts.id', '=', 'orders.contract_id')
            ->where('debts.customer_id', $request->user()->id)->where('debts.direction', 'receivable')
            ->whereNotIn('orders.status', ['awaiting_confirmation', 'awaiting_contract', 'rejected', 'cancelled'])
            ->where(fn ($query) => $query->where('orders.requires_contract', false)->orWhere('contracts.status', 'signed'))
            ->select('debts.*')->orderByDesc('debts.id')->get();
        $credits = DB::table('customer_credits')->where('customer_id', $request->user()->id)
            ->orderByDesc('id')->get();
        $creditUsed = DB::table('customer_credit_movements')->whereIn('customer_credit_id', $credits->pluck('id'))
            ->select('customer_credit_id')->selectRaw('SUM(amount) as total')
            ->groupBy('customer_credit_id')->pluck('total', 'customer_credit_id');
        $paymentSchedules = app(PaymentScheduleWorkflow::class)->summariesForDebts($debts->pluck('id')->all());
        $debtReconciliations = DB::table('debt_reconciliations')->where('customer_id', $request->user()->id)
            ->where('status', '!=', 'draft')->orderByDesc('as_of_date')->orderByDesc('id')->limit(5)->get();

        return view('store.profile', compact('debts', 'credits', 'creditUsed', 'paymentSchedules', 'debtReconciliations'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'regex:/^[0-9+() .-]{9,20}$/', Rule::unique('users', 'phone')->ignore($request->user()->id)],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'address' => ['required', 'string', 'max:1000'],
        ]);
        $request->user()->update($data);

        return back()->with('success', 'Đã cập nhật thông tin.');
    }
}
