<?php

namespace App\Services;

use App\Support\UiFormat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommerceWorkflow
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }

    private function code(string $prefix, int $id): string
    {
        return $prefix.'-'.now()->format('Y').'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function requestQuote(int $customerId, array $items, string $address, ?string $note = null): int
    {
        return DB::transaction(function () use ($customerId, $items, $address, $note) {
            if ($items === [] || trim($address) === '') {
                $this->fail('Cần chọn vật liệu và nhập địa chỉ giao hàng.');
            }
            $id = DB::table('quotes')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'customer_id' => $customerId,
                'delivery_address' => trim($address), 'customer_note' => $note,
                'status' => 'requested', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $total = '0.00';
            foreach ($items as $productId => $quantity) {
                $product = DB::table('products')->where('id', $productId)->where('active', true)->first();
                if (! $product || ! is_numeric($quantity) || ! preg_match('/^\d+(?:\.\d{1,3})?$/', (string) $quantity)
                    || bccomp((string) $quantity, '0', 3) <= 0) {
                    $this->fail('Sản phẩm hoặc số lượng không hợp lệ.');
                }
                if (bccomp((string) $quantity, '1000000', 3) > 0
                    || (! in_array($product->unit, ['m³', 'kg'], true) && floor((float) $quantity) !== (float) $quantity)) {
                    $this->fail('Số lượng vật liệu không hợp lệ.');
                }
                $line = bcmul((string) $product->price, (string) $quantity, 2);
                $total = bcadd($total, $line, 2);
                DB::table('quote_items')->insert([
                    'quote_id' => $id, 'product_id' => $productId, 'quantity' => $quantity,
                    'unit_price' => $product->price, 'line_total' => $line,
                    'specification' => $product->specification,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('quotes')->where('id', $id)->update(['code' => $this->code('BG', $id), 'total' => $total]);

            return $id;
        });
    }

    public function updateRequestedQuote(int $quoteId, int $customerId, array $quantities, string $address, ?string $note): void
    {
        DB::transaction(function () use ($quoteId, $customerId, $quantities, $address, $note) {
            $quote = DB::table('quotes')->where('id', $quoteId)->where('customer_id', $customerId)->lockForUpdate()->first();
            if (! $quote || $quote->status !== 'requested') {
                $this->fail('Chỉ có thể sửa yêu cầu báo giá chưa được xử lý.');
            }
            $items = DB::table('quote_items')->join('products', 'products.id', '=', 'quote_items.product_id')
                ->where('quote_id', $quoteId)->select('quote_items.*', 'products.unit')->get();
            $total = '0.00';
            foreach ($items as $item) {
                $quantity = $quantities[$item->id] ?? null;
                if (! preg_match('/^\d+(?:\.\d{1,3})?$/', (string) $quantity) || bccomp((string) $quantity, '0', 3) <= 0
                    || bccomp((string) $quantity, '1000000', 3) > 0
                    || (! in_array($item->unit, ['m³', 'kg'], true) && floor((float) $quantity) !== (float) $quantity)) {
                    $this->fail('Số lượng vật liệu không hợp lệ.');
                }
                $line = bcmul((string) $item->unit_price, (string) $quantity, 2);
                $total = bcadd($total, $line, 2);
                DB::table('quote_items')->where('id', $item->id)->update(['quantity' => $quantity, 'line_total' => $line, 'updated_at' => now()]);
            }
            DB::table('quotes')->where('id', $quoteId)->update([
                'delivery_address' => trim($address), 'customer_note' => $note,
                'total' => $total, 'updated_at' => now(),
            ]);
        });
    }

    public function withdrawRequestedQuote(int $quoteId, int $customerId): void
    {
        $changed = DB::table('quotes')->where('id', $quoteId)->where('customer_id', $customerId)
            ->where('status', 'requested')->update(['status' => 'cancelled', 'updated_at' => now()]);
        if (! $changed) {
            $this->fail('Chỉ có thể rút yêu cầu báo giá chưa được xử lý.');
        }
    }

    public function updateUnpaidPickupOrder(int $orderId, int $customerId, array $quantities): void
    {
        DB::transaction(function () use ($orderId, $customerId, $quantities) {
            $order = DB::table('orders')->where('id', $orderId)->where('customer_id', $customerId)->lockForUpdate()->first();
            if (! $order || $order->fulfillment_method !== 'pickup' || ! in_array($order->status, ['awaiting_confirmation', 'awaiting_payment'], true)
                || bccomp((string) $order->paid, '0', 2) !== 0) {
                $this->fail('Chỉ có thể sửa đơn nhận tại kho chưa thanh toán.');
            }
            DB::table('warehouses')->where('id', $order->pickup_warehouse_id)->lockForUpdate()->first();
            $items = DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
                ->where('order_id', $orderId)->select('order_items.*', 'products.unit', 'products.name as product_name')->get();
            $total = '0.00';
            $requiresReview = false;
            $shortages = [];
            foreach ($items as $item) {
                $quantity = $quantities[$item->id] ?? null;
                if (! preg_match('/^\d+(?:\.\d{1,3})?$/', (string) $quantity) || bccomp((string) $quantity, '0', 3) <= 0
                    || bccomp((string) $quantity, '1000000', 3) > 0
                    || (! in_array($item->unit, ['m³', 'kg'], true) && floor((float) $quantity) !== (float) $quantity)) {
                    $this->fail('Số lượng vật liệu không hợp lệ.');
                }
                $stock = DB::table('inventory')->where('warehouse_id', $order->pickup_warehouse_id)
                    ->where('product_id', $item->product_id)->lockForUpdate()->first();
                $reserved = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.pickup_warehouse_id', $order->pickup_warehouse_id)
                    ->whereIn('orders.status', ['awaiting_contract', 'awaiting_payment', 'ready_to_issue'])
                    ->where('orders.id', '!=', $orderId)->where('order_items.product_id', $item->product_id)
                    ->sum('order_items.quantity');
                if (bccomp(bcsub((string) ($stock->quantity ?? 0), (string) $reserved, 3), (string) $quantity, 3) < 0) {
                    $available = bcsub((string) ($stock->quantity ?? 0), (string) $reserved, 3);
                    if ($order->status !== 'awaiting_confirmation') {
                        $this->fail('Đơn đã được xác nhận; vui lòng liên hệ Kinh doanh để thay đổi số lượng khi kho thiếu hàng.');
                    }
                    $shortages[] = $item->product_name.' còn '.UiFormat::quantity(bccomp($available, '0', 3) > 0 ? $available : '0')
                        .' '.$item->unit.' (yêu cầu '.UiFormat::quantity($quantity).' '.$item->unit.')';
                }
                $line = bcmul((string) $item->unit_price, (string) $quantity, 2);
                $total = bcadd($total, $line, 2);
                $requiresReview = $requiresReview || bccomp((string) $item->quantity, (string) $quantity, 3) !== 0;
                DB::table('order_items')->where('id', $item->id)->update(['quantity' => $quantity, 'line_total' => $line, 'updated_at' => now()]);
            }
            DB::table('orders')->where('id', $orderId)->update([
                'total' => $total, 'stock_shortage_note' => $shortages ? 'Thiếu tồn cho số lượng yêu cầu: '.implode(', ', $shortages) : null,
                'estimated_available_on' => $shortages ? now()->addDays(14)->toDateString() : null,
                'updated_at' => now(),
            ] + ($requiresReview ? [
                'status' => 'awaiting_confirmation', 'reviewed_by' => null, 'reviewed_at' => null,
            ] : []));
            DB::table('debts')->where('order_id', $orderId)->update(['amount' => $total, 'updated_at' => now()]);
            $debtId = DB::table('debts')->where('order_id', $orderId)->where('direction', 'receivable')->value('id');
            if ($debtId && bccomp($total, '0', 2) > 0) {
                app(PaymentScheduleWorkflow::class)->replaceDraftSchedule((int) $debtId, [[
                    'sequence' => 1, 'label' => 'Thanh toán đủ trước khi nhận hàng', 'amount' => $total,
                    'due_on' => null, 'trigger' => 'before_issue', 'days_after_trigger' => null,
                    'required_before_issue' => true,
                ]], $customerId);
            }
        });
    }

    public function placePickupOrder(int $customerId, array $items, int $warehouseId, ?string $idempotencyKey = null): int
    {
        return DB::transaction(function () use ($customerId, $items, $warehouseId, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                $customer = DB::table('users')->where('id', $customerId)->where('role', 'customer')->lockForUpdate()->first();
                if (! $customer) {
                    $this->fail('Tài khoản khách hàng không hợp lệ.');
                }
                $existing = DB::table('orders')->where('customer_id', $customerId)
                    ->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return (int) $existing->id;
                }
            }

            $warehouse = DB::table('warehouses')->where('id', $warehouseId)->where('active', true)->lockForUpdate()->first();
            if (! $warehouse || $items === []) {
                $this->fail('Cần chọn kho nhận hàng và ít nhất một vật liệu.');
            }
            $lines = [];
            $total = '0.00';
            $shortages = [];
            foreach ($items as $productId => $quantity) {
                $product = DB::table('products')->where('id', $productId)->where('active', true)->first();
                if (! $product || ! $product->direct_purchase_allowed || bccomp((string) ($product->price ?? 0), '0', 2) <= 0
                    || ! is_numeric($quantity) || ! preg_match('/^\d+(?:\.\d{1,3})?$/', (string) $quantity)
                    || bccomp((string) $quantity, '0', 3) <= 0
                    || bccomp((string) $quantity, '1000000', 3) > 0
                    || (! in_array($product->unit, ['m³', 'kg'], true) && floor((float) $quantity) !== (float) $quantity)) {
                    $this->fail('Vật liệu hoặc số lượng trong giỏ không hợp lệ.');
                }
                $stock = DB::table('inventory')->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)->lockForUpdate()->first();
                $reserved = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.pickup_warehouse_id', $warehouseId)
                    ->whereIn('orders.status', ['awaiting_contract', 'awaiting_payment', 'ready_to_issue'])
                    ->where('order_items.product_id', $productId)->sum('order_items.quantity');
                if (bccomp(bcsub((string) ($stock->quantity ?? 0), (string) $reserved, 3), (string) $quantity, 3) < 0) {
                    $available = bcsub((string) ($stock->quantity ?? 0), (string) $reserved, 3);
                    $shortages[] = $product->name.' còn '.UiFormat::quantity(bccomp($available, '0', 3) > 0 ? $available : '0')
                        .' '.$product->unit.' tại kho đã chọn (yêu cầu '.UiFormat::quantity($quantity).' '.$product->unit.')';
                }
                $line = bcmul((string) $product->price, (string) $quantity, 2);
                $total = bcadd($total, $line, 2);
                $lines[] = ['product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $product->price, 'line_total' => $line];
            }
            $id = DB::table('orders')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'customer_id' => $customerId, 'contract_id' => null,
                'status' => 'awaiting_confirmation', 'total' => $total, 'fulfillment_method' => 'pickup', 'requires_contract' => false,
                'pickup_warehouse_id' => $warehouseId, 'delivery_address' => $warehouse->address ?: $warehouse->name,
                'stock_shortage_note' => $shortages ? implode('; ', $shortages) : null,
                'estimated_available_on' => $shortages ? now()->addDays(14)->toDateString() : null,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('orders')->where('id', $id)->update(['code' => $this->code('DH', $id)]);
            foreach ($lines as $line) {
                DB::table('order_items')->insert($line + ['order_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $debtId = DB::table('debts')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'direction' => 'receivable', 'customer_id' => $customerId,
                'order_id' => $id, 'amount' => $total, 'status' => 'open',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('debts')->where('id', $debtId)->update(['code' => $this->code('CN', $debtId)]);
            app(PaymentScheduleWorkflow::class)->ensureLegacySchedule($debtId);

            return $id;
        });
    }

    public function cancelPickupOrder(int $orderId, int $customerId): void
    {
        DB::transaction(function () use ($orderId, $customerId) {
            $order = DB::table('orders')->where('id', $orderId)->where('customer_id', $customerId)->lockForUpdate()->first();
            if (! $order || $order->fulfillment_method !== 'pickup' || ! in_array($order->status, ['awaiting_confirmation', 'awaiting_payment'], true) || bccomp((string) $order->paid, '0', 2) !== 0) {
                $this->fail('Chỉ có thể hủy đơn nhận tại kho khi chưa thanh toán.');
            }
            DB::table('warehouses')->where('id', $order->pickup_warehouse_id)->lockForUpdate()->first();
            $cancelledAt = now();
            DB::table('orders')->where('id', $orderId)->update([
                'status' => 'cancelled', 'cancelled_at' => $cancelledAt, 'updated_at' => $cancelledAt,
            ]);
            DB::table('debts')->where('order_id', $orderId)->update(['amount' => 0, 'status' => 'settled', 'updated_at' => now()]);
        });
    }

    public function reviewOrder(int $orderId, int $salespersonId, bool $confirmed, ?string $reason = null): void
    {
        DB::transaction(function () use ($orderId, $salespersonId, $confirmed, $reason) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'awaiting_confirmation') {
                $this->fail('Chỉ có thể xử lý đơn đang chờ xác nhận.');
            }
            if (! $confirmed && trim((string) $reason) === '') {
                $this->fail('Cần ghi lý do từ chối đơn hàng.');
            }
            if ($confirmed && $order->fulfillment_method === 'pickup') {
                DB::table('warehouses')->where('id', $order->pickup_warehouse_id)->lockForUpdate()->first();
                foreach (DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
                    ->where('order_items.order_id', $orderId)->select('order_items.*', 'products.name as product_name')->get() as $item) {
                    $stock = DB::table('inventory')->where('warehouse_id', $order->pickup_warehouse_id)
                        ->where('product_id', $item->product_id)->lockForUpdate()->first();
                    $reserved = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                        ->where('orders.pickup_warehouse_id', $order->pickup_warehouse_id)
                        ->whereIn('orders.status', ['awaiting_contract', 'awaiting_payment', 'ready_to_issue'])
                        ->where('orders.id', '!=', $orderId)->where('order_items.product_id', $item->product_id)
                        ->sum('order_items.quantity');
                    if (bccomp(bcsub((string) ($stock->quantity ?? 0), (string) $reserved, 3), (string) $item->quantity, 3) < 0) {
                        $this->fail($item->product_name.' không đủ tồn tại kho nhận hàng.');
                    }
                }
            }
            DB::table('orders')->where('id', $orderId)->update([
                'status' => $confirmed
                    ? ($order->requires_contract ? 'awaiting_contract' : 'awaiting_payment')
                    : 'rejected',
                'stock_shortage_note' => $confirmed ? null : $order->stock_shortage_note,
                'estimated_available_on' => $confirmed ? null : $order->estimated_available_on,
                'reviewed_by' => $salespersonId, 'reviewed_at' => now(),
                'rejection_reason' => $confirmed ? null : trim($reason), 'updated_at' => now(),
            ]);
            if (! $confirmed) {
                DB::table('debts')->where('order_id', $orderId)->update([
                    'amount' => 0, 'status' => 'settled', 'updated_at' => now(),
                ]);
            }
        });
    }

    public function sendQuote(int $quoteId, int $salespersonId, array $prices, string $validUntil, ?string $estimatedAvailableOn = null, ?string $alternativeMaterial = null): void
    {
        DB::transaction(function () use ($quoteId, $salespersonId, $prices, $validUntil, $estimatedAvailableOn, $alternativeMaterial) {
            $quote = DB::table('quotes')->where('id', $quoteId)->lockForUpdate()->first();
            if (! $quote || ! in_array($quote->status, ['requested', 'draft'], true)) {
                $this->fail('Báo giá không còn ở trạng thái có thể gửi.');
            }
            if ($validUntil < now()->toDateString()) {
                $this->fail('Ngày hết hạn báo giá phải từ hôm nay trở đi.');
            }
            $total = '0.00';
            $discounted = false;
            $shortages = [];
            foreach (DB::table('quote_items')->where('quote_id', $quoteId)->get() as $item) {
                $price = (string) ($prices[$item->id] ?? $item->unit_price);
                if (! is_numeric($price) || bccomp($price, '0', 2) < 0) {
                    $this->fail('Đơn giá không hợp lệ.');
                }
                $catalogPrice = DB::table('products')->where('id', $item->product_id)->value('price');
                if (bccomp($price, (string) $catalogPrice, 2) < 0) {
                    $discounted = true;
                }
                $line = bcmul($price, (string) $item->quantity, 2);
                $total = bcadd($total, $line, 2);
                $available = (string) DB::table('inventory')->where('product_id', $item->product_id)->sum('quantity');
                if (bccomp($available, (string) $item->quantity, 3) < 0) {
                    $product = DB::table('products')->where('id', $item->product_id)->first();
                    $shortages[] = $product->name.' (cần '.UiFormat::quantity($item->quantity).', hiện có '.UiFormat::quantity($available).')';
                }
                DB::table('quote_items')->where('id', $item->id)->update(['unit_price' => $price, 'line_total' => $line, 'updated_at' => now()]);
            }
            $stockNote = $shortages ? 'Vật liệu thiếu tồn: '.implode('; ', $shortages).'. Dự kiến có hàng: '
                .Carbon::parse($estimatedAvailableOn ?: now()->addDays(14))->format('d/m/Y')
                .'. Vui lòng xác nhận lại ngày thực tế với nhân viên Kinh doanh.' : null;
            if ($stockNote && trim((string) $alternativeMaterial) !== '') {
                $stockNote .= ' Vật liệu thay thế đề xuất: '.trim($alternativeMaterial).'.';
            }
            DB::table('quotes')->where('id', $quoteId)->update([
                'salesperson_id' => $salespersonId, 'total' => $total, 'valid_until' => $validUntil,
                'staff_note' => $stockNote,
                'status' => $discounted ? 'pending_discount_approval' : 'sent',
                'sent_at' => $discounted ? null : now(), 'updated_at' => now(),
            ]);
        });
    }

    public function reviewQuoteDiscount(int $quoteId, int $managerId, bool $approve, ?string $note = null): void
    {
        DB::transaction(function () use ($quoteId, $managerId, $approve, $note) {
            $quote = DB::table('quotes')->where('id', $quoteId)->lockForUpdate()->first();
            if (! $quote || $quote->status !== 'pending_discount_approval') {
                $this->fail('Báo giá không chờ duyệt chiết khấu.');
            }
            app(ApprovalWorkflow::class)->assertIndependent($quote->salesperson_id ? (int) $quote->salesperson_id : null,
                $managerId, 'chiết khấu báo giá');
            if (! $approve && trim((string) $note) === '') {
                $this->fail('Cần nêu lý do từ chối đề xuất chiết khấu.');
            }

            $content = [
                'quote' => array_intersect_key((array) $quote, array_flip([
                    'id', 'code', 'customer_id', 'salesperson_id', 'delivery_address', 'total', 'valid_until',
                ])),
                'items' => DB::table('quote_items')->where('quote_id', $quoteId)->orderBy('id')->get([
                    'id', 'product_id', 'quantity', 'unit_price', 'line_total', 'specification',
                ])->map(fn ($item) => (array) $item)->all(),
            ];
            $statusTo = $approve ? 'sent' : 'draft';
            $changed = DB::table('quotes')->where('id', $quoteId)->where('status', 'pending_discount_approval')
                ->update([
                    'status' => $statusTo, 'discount_approved_by' => $approve ? $managerId : null,
                    'discount_review_note' => trim((string) $note) !== '' ? trim($note) : null,
                    'sent_at' => $approve ? now() : null, 'updated_at' => now(),
                ]);
            if (! $changed) {
                $this->fail('Báo giá không chờ duyệt chiết khấu.');
            }

            app(ApprovalWorkflow::class)->recordDecision('quote_discount', $quoteId, $approve ? 'approved' : 'rejected',
                'pending_discount_approval', $statusTo, $managerId, trim((string) $note) !== '' ? trim($note) : null, $content);
        });
    }

    public function acceptQuote(int $quoteId, int $customerId): int
    {
        return DB::transaction(function () use ($quoteId, $customerId) {
            $quote = DB::table('quotes')->where('id', $quoteId)->lockForUpdate()->first();
            if (! $quote || $quote->customer_id !== $customerId || $quote->status !== 'sent') {
                $this->fail('Không thể chấp nhận báo giá này.');
            }
            if ($quote->valid_until && $quote->valid_until < now()->toDateString()) {
                $this->fail('Báo giá đã hết hạn.');
            }
            DB::table('quotes')->where('id', $quoteId)->update(['status' => 'accepted', 'accepted_at' => now(), 'updated_at' => now()]);
            $orderId = DB::table('orders')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'quote_id' => $quoteId, 'customer_id' => $customerId,
                'status' => 'awaiting_confirmation', 'total' => $quote->total,
                'fulfillment_method' => 'delivery', 'delivery_address' => $quote->delivery_address,
                'requires_contract' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('orders')->where('id', $orderId)->update(['code' => $this->code('DH', $orderId)]);
            foreach (DB::table('quote_items')->where('quote_id', $quoteId)->get() as $item) {
                DB::table('order_items')->insert([
                    'order_id' => $orderId, 'product_id' => $item->product_id, 'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price, 'line_total' => $item->line_total,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $debtId = DB::table('debts')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'direction' => 'receivable', 'customer_id' => $customerId,
                'order_id' => $orderId, 'amount' => $quote->total, 'status' => 'open',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('debts')->where('id', $debtId)->update(['code' => $this->code('CN', $debtId)]);

            return $orderId;
        });
    }

    public function createContract(int $orderId, int $staffId, string $deposit, string $paymentTerms, string $deliveryTerms, ?array $installments = null): int
    {
        return DB::transaction(function () use ($orderId, $staffId, $deposit, $paymentTerms, $deliveryTerms, $installments) {
            $allowUndatedLegacyTerms = $installments === null;
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || ! $order->requires_contract || $order->status !== 'awaiting_contract'
                || DB::table('contracts')->where('order_id', $orderId)->exists()) {
                $this->fail('Đơn chưa được xác nhận hoặc đã có hợp đồng.');
            }
            if (! is_numeric($deposit) || bccomp($deposit, '0', 2) < 0 || bccomp($deposit, (string) $order->total, 2) > 0) {
                $this->fail('Tiền cọc phải nằm trong giá trị đơn hàng.');
            }
            $id = DB::table('contracts')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'order_id' => $orderId, 'quote_id' => $order->quote_id,
                'created_by' => $staffId, 'last_edited_by' => $staffId, 'last_submitted_by' => null,
                'status' => 'draft', 'total' => $order->total, 'deposit_required' => $deposit,
                'payment_terms' => $paymentTerms, 'delivery_terms' => $deliveryTerms,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('contracts')->where('id', $id)->update(['code' => $this->code('HD', $id)]);
            $debt = DB::table('debts')->where('order_id', $orderId)->where('direction', 'receivable')->first();
            if ($debt) {
                $installments ??= $this->defaultContractSchedule((string) $order->total, $deposit);
                app(PaymentScheduleWorkflow::class)->replaceDraftSchedule(
                    (int) $debt->id, $installments, $staffId, $allowUndatedLegacyTerms
                );
            } else {
                $installments ??= $this->defaultContractSchedule((string) $order->total, $deposit);
                app(PaymentScheduleWorkflow::class)->saveContractDraftSchedule(
                    $id, $installments, $staffId, $deposit, $allowUndatedLegacyTerms
                );
            }

            return $id;
        });
    }

    private function defaultContractSchedule(string $total, string $deposit): array
    {
        $rows = [];
        if (bccomp($deposit, '0', 2) > 0) {
            $rows[] = [
                'sequence' => 1, 'label' => 'Thanh toán trước khi xuất hàng', 'amount' => $deposit,
                'due_on' => null, 'trigger' => 'before_issue', 'days_after_trigger' => null,
                'required_before_issue' => true,
            ];
        }
        $remaining = bcsub($total, $deposit, 2);
        if (bccomp($remaining, '0', 2) > 0) {
            $rows[] = [
                'sequence' => count($rows) + 1, 'label' => 'Thanh toán còn lại theo hợp đồng', 'amount' => $remaining,
                'due_on' => null, 'trigger' => 'after_handover', 'days_after_trigger' => null,
                'required_before_issue' => false,
            ];
        }

        return $rows;
    }

    public function submitContract(int $contractId, int $submitterId): void
    {
        $changed = DB::table('contracts')->where('id', $contractId)->whereIn('status', ['draft', 'changes_requested'])
            ->update(['status' => 'pending_approval', 'last_submitted_by' => $submitterId, 'updated_at' => now()]);
        if (! $changed) {
            $this->fail('Hợp đồng không thể trình duyệt.');
        }
    }

    public function reviewContract(int $contractId, int $managerId, bool $approve, ?string $note = null): void
    {
        DB::transaction(function () use ($contractId, $managerId, $approve, $note) {
            $contract = DB::table('contracts')->where('id', $contractId)->lockForUpdate()->first();
            if (! $contract || $contract->status !== 'pending_approval') {
                $this->fail('Hợp đồng không ở trạng thái chờ duyệt.');
            }
            $independentApproval = app(ApprovalWorkflow::class);
            $requesters = array_unique(array_map('intval', array_filter([
                $contract->created_by,
                $contract->last_edited_by ?? $contract->created_by,
                $contract->last_submitted_by,
            ], fn ($requesterId) => $requesterId !== null)));
            foreach ($requesters as $requesterId) {
                $independentApproval->assertIndependent($requesterId, $managerId, 'hợp đồng');
            }
            if (! $approve && trim((string) $note) === '') {
                $this->fail('Cần nêu lý do yêu cầu sửa hợp đồng.');
            }

            $content = $this->contractApprovalSnapshot($contract);
            $statusTo = $approve ? 'approved' : 'changes_requested';
            $changed = DB::table('contracts')->where('id', $contractId)->where('status', 'pending_approval')->update([
                'status' => $statusTo,
                'approved_by' => $approve ? $managerId : null,
                'approval_note' => trim((string) $note) !== '' ? trim($note) : null,
                'customer_confirmed_at' => null, 'customer_note' => null, 'updated_at' => now(),
            ]);
            if (! $changed) {
                $this->fail('Hợp đồng không ở trạng thái chờ duyệt.');
            }

            app(ApprovalWorkflow::class)->recordDecision('contract', $contractId, $approve ? 'approved' : 'changes_requested',
                'pending_approval', $statusTo, $managerId, trim((string) $note) !== '' ? trim($note) : null, $content);
        });
    }

    public function respondToContract(int $contractId, int $customerId, bool $accept, ?string $note = null): void
    {
        DB::transaction(function () use ($contractId, $customerId, $accept, $note) {
            $contract = DB::table('contracts')->where('id', $contractId)->lockForUpdate()->first();
            $ownerId = $contract?->order_id
                ? DB::table('orders')->where('id', $contract->order_id)->value('customer_id')
                : DB::table('quotes')->where('id', $contract?->quote_id)->value('customer_id');
            if (! $contract || $ownerId !== $customerId || $contract->status !== 'approved' || $contract->customer_confirmed_at) {
                $this->fail('Hợp đồng không còn chờ khách hàng xác nhận.');
            }
            if (! $accept && trim((string) $note) === '') {
                $this->fail('Cần nêu lý do yêu cầu sửa hợp đồng.');
            }
            DB::table('contracts')->where('id', $contractId)->update([
                'status' => $accept ? 'approved' : 'changes_requested',
                'customer_confirmed_at' => $accept ? now() : null,
                'customer_note' => $accept ? null : trim($note),
                'updated_at' => now(),
            ]);
            if (! $accept) {
                $content = $this->contractApprovalSnapshot($contract);
                $content['customer_response'] = ['decision' => 'changes_requested', 'note' => trim((string) $note)];
                app(ApprovalWorkflow::class)->recordDecision(
                    'contract', $contractId, 'customer_changes_requested', 'approved', 'changes_requested',
                    $customerId, trim((string) $note), $content
                );
            }
        });
    }

    private function contractApprovalSnapshot(object $contract): array
    {
        $debtId = $contract->order_id
            ? DB::table('debts')->where('order_id', $contract->order_id)->where('direction', 'receivable')->value('id')
            : null;

        return [
            'contract' => array_intersect_key((array) $contract, array_flip([
                'id', 'code', 'quote_id', 'order_id', 'created_by', 'last_edited_by', 'last_submitted_by',
                'total', 'deposit_required', 'payment_terms', 'delivery_terms', 'payment_schedule_draft',
            ])),
            'payment_installments' => $debtId
                ? DB::table('payment_installments')->where('debt_id', $debtId)->orderBy('sequence')->get([
                    'sequence', 'label', 'amount', 'due_on', 'trigger', 'days_after_trigger', 'required_before_issue',
                ])->map(fn ($row) => (array) $row)->all()
                : [],
            'order_items' => $contract->order_id
                ? DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
                    ->where('order_items.order_id', $contract->order_id)->orderBy('order_items.id')->get([
                        'order_items.id', 'order_items.product_id', 'products.name as product_name',
                        'products.specification as product_specification', 'products.unit as product_unit',
                        'order_items.quantity', 'order_items.unit_price', 'order_items.line_total',
                    ])->map(fn ($row) => (array) $row)->all()
                : [],
        ];
    }

    public function cancelContract(int $contractId, int $managerId, string $reason): void
    {
        if (trim($reason) === '') {
            $this->fail('Cần ghi lý do hủy hợp đồng.');
        }
        DB::transaction(function () use ($contractId, $managerId, $reason) {
            $contract = DB::table('contracts')->where('id', $contractId)->lockForUpdate()->first();
            if (! $contract || ! in_array($contract->status, ['draft', 'pending_approval', 'changes_requested', 'approved'], true)) {
                $this->fail('Chỉ được hủy hợp đồng trước khi ký.');
            }
            DB::table('contracts')->where('id', $contractId)->update([
                'status' => 'cancelled', 'cancelled_by' => $managerId,
                'cancelled_at' => now(), 'cancellation_note' => trim($reason), 'updated_at' => now(),
            ]);
            if ($contract->order_id) {
                $cancelledAt = now();
                DB::table('orders')->where('id', $contract->order_id)->where('status', 'awaiting_contract')
                    ->update(['status' => 'cancelled', 'cancelled_at' => $cancelledAt, 'updated_at' => $cancelledAt]);
                DB::table('debts')->where('order_id', $contract->order_id)->update([
                    'amount' => 0, 'status' => 'settled', 'updated_at' => now(),
                ]);
            }
        });
    }

    public function signContract(int $contractId, string $documentPath, ?int $signingStaffId = null): int
    {
        return DB::transaction(function () use ($contractId, $documentPath, $signingStaffId) {
            $contract = DB::table('contracts')->where('id', $contractId)->lockForUpdate()->first();
            if (! $contract || $contract->status !== 'approved' || ! $contract->customer_confirmed_at) {
                $this->fail('Hợp đồng phải được quản lý và khách hàng xác nhận trước khi ký.');
            }
            if ($contract->order_id) {
                $order = DB::table('orders')->where('id', $contract->order_id)->lockForUpdate()->first();
                if (! $order || $order->status !== 'awaiting_contract' || $order->contract_id) {
                    $this->fail('Đơn hàng không còn chờ ký hợp đồng.');
                }
            } else {
                $quote = DB::table('quotes')->where('id', $contract->quote_id)->first();
                if (! $quote || DB::table('orders')->where('quote_id', $quote->id)->exists()) {
                    $this->fail('Báo giá của hợp đồng cũ đã có đơn hàng.');
                }
                $orderId = DB::table('orders')->insertGetId([
                    'code' => 'TEMP-'.uniqid(), 'quote_id' => $quote->id,
                    'customer_id' => $quote->customer_id, 'status' => 'awaiting_contract',
                    'total' => $contract->total, 'delivery_address' => $quote->delivery_address,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('orders')->where('id', $orderId)->update(['code' => $this->code('DH', $orderId)]);
                foreach (DB::table('quote_items')->where('quote_id', $quote->id)->get() as $item) {
                    DB::table('order_items')->insert([
                        'order_id' => $orderId, 'product_id' => $item->product_id,
                        'quantity' => $item->quantity, 'unit_price' => $item->unit_price,
                        'line_total' => $item->line_total, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $debtId = DB::table('debts')->insertGetId([
                    'code' => 'TEMP-'.uniqid(), 'direction' => 'receivable',
                    'customer_id' => $quote->customer_id, 'order_id' => $orderId,
                    'amount' => $contract->total, 'status' => 'open',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('debts')->where('id', $debtId)->update(['code' => $this->code('CN', $debtId)]);
                DB::table('contracts')->where('id', $contractId)->update(['order_id' => $orderId]);
                $order = DB::table('orders')->where('id', $orderId)->first();
            }
            DB::table('orders')->where('id', $order->id)->update([
                'contract_id' => $contractId,
                'status' => 'awaiting_payment',
                'updated_at' => now(),
            ]);
            $debt = DB::table('debts')->where('order_id', $order->id)->where('direction', 'receivable')->first();
            $schedule = app(PaymentScheduleWorkflow::class);
            if ($debt && ! $schedule->applyContractDraftSchedule(
                $contractId, (int) $debt->id, $signingStaffId ?? (int) $contract->created_by
            )) {
                $schedule->ensureLegacySchedule((int) $debt->id);
            }
            DB::table('contracts')->where('id', $contractId)->update([
                'status' => 'signed', 'document_path' => $documentPath,
                'signed_at' => now(), 'updated_at' => now(),
            ]);
            if ($debt) {
                $schedule->activateSigningTerms((int) $order->id);
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => $schedule->isReleaseEligible((int) $order->id) ? 'ready_to_issue' : 'awaiting_payment',
                    'updated_at' => now(),
                ]);
            }

            return $order->id;
        });
    }

    public function recordPayment(int $debtId, int $accountantId, string $amount, string $method, ?string $reference = null, ?string $idempotencyKey = null): int
    {
        return DB::transaction(function () use ($debtId, $accountantId, $amount, $method, $reference, $idempotencyKey) {
            $debt = DB::table('debts')->where('id', $debtId)->lockForUpdate()->first();
            if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
                $this->fail('Số tiền phải là số dương với tối đa hai chữ số thập phân.');
            }
            if ($idempotencyKey !== null) {
                $prior = DB::table('payments')->where('idempotency_key', $idempotencyKey)->first();
                if ($prior) {
                    if ((int) $prior->debt_id !== $debtId
                        || bccomp((string) $prior->amount, $amount, 2) !== 0 || $prior->method !== $method
                        || (string) ($prior->reference ?? '') !== (string) ($reference ?? '')) {
                        $this->fail('Mã gửi lại đã được dùng cho một giao dịch thanh toán khác.');
                    }

                    return (int) $prior->id;
                }
            }
            if (! $debt || bccomp($amount, '0', 2) <= 0 ||
                bccomp($amount, bcsub((string) $debt->amount, (string) $debt->paid, 2), 2) > 0) {
                $this->fail('Số tiền thanh toán vượt số dư hoặc không hợp lệ.');
            }
            if (! in_array($method, ['cash', 'bank_transfer'], true)) {
                $this->fail('Phương thức thanh toán không hợp lệ.');
            }
            if ($debt->order_id) {
                $order = DB::table('orders')->where('id', $debt->order_id)->lockForUpdate()->first();
                $contract = $order?->contract_id ? DB::table('contracts')->where('id', $order->contract_id)->first() : null;
                if (! $order || in_array($order->status, ['awaiting_confirmation', 'awaiting_contract', 'rejected', 'cancelled'], true)
                    || ($order->requires_contract && $contract?->status !== 'signed')) {
                    $this->fail('Đơn hàng chưa có hợp đồng đã ký hoặc đã kết thúc.');
                }
            }
            $id = DB::table('payments')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'debt_id' => $debtId, 'recorded_by' => $accountantId,
                'amount' => $amount, 'method' => $method, 'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('payments')->where('id', $id)->update(['code' => $this->code('TT', $id)]);
            if ($debt->direction === 'receivable') {
                app(PaymentScheduleWorkflow::class)->allocatePayment($id);
            }
            $paid = bcadd((string) $debt->paid, $amount, 2);
            DB::table('debts')->where('id', $debtId)->update([
                'paid' => $paid, 'status' => bccomp($paid, (string) $debt->amount, 2) >= 0 ? 'settled' : 'open',
                'updated_at' => now(),
            ]);
            if ($debt->order_id) {
                $order = DB::table('orders')->where('id', $debt->order_id)->lockForUpdate()->first();
                $changes = ['paid' => $paid, 'updated_at' => now()];
                if ($order->status === 'awaiting_payment'
                    && app(PaymentScheduleWorkflow::class)->isReleaseEligible((int) $order->id)) {
                    $changes['status'] = 'ready_to_issue';
                }
                DB::table('orders')->where('id', $order->id)->update($changes);
            }

            return $id;
        });
    }

    public function useCustomerCredit(int $creditId, int $accountantId, string $amount, string $type, ?int $debtId = null, ?string $method = null, ?string $reference = null, ?string $idempotencyKey = null): int
    {
        return DB::transaction(function () use ($creditId, $accountantId, $amount, $type, $debtId, $method, $reference, $idempotencyKey) {
            $credit = DB::table('customer_credits')->where('id', $creditId)->lockForUpdate()->first();
            if (! $credit || ! Str::isUuid($idempotencyKey) || ! preg_match('/^\d+(\.\d{1,2})?$/', $amount)
                || bccomp($amount, '0', 2) <= 0 || ! in_array($type, ['refund', 'offset'], true)) {
                $this->fail('Khoản ghi có hoặc số tiền xử lý không hợp lệ.');
            }
            $existing = DB::table('customer_credit_movements')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $sameRequest = (int) $existing->customer_credit_id === $creditId
                    && (int) $existing->recorded_by === $accountantId
                    && $existing->type === $type && bccomp((string) $existing->amount, $amount, 2) === 0
                    && (int) ($existing->debt_id ?? 0) === (int) ($debtId ?? 0)
                    && $existing->method === ($type === 'refund' ? $method : null)
                    && $existing->reference === $reference;
                if (! $sameRequest) {
                    $this->fail('Mã thao tác đã được dùng cho một yêu cầu hoàn tiền hoặc cấn trừ khác.');
                }

                return (int) $existing->id;
            }
            if ($credit->status !== 'open') {
                $this->fail('Khoản ghi có hoặc số tiền xử lý không hợp lệ.');
            }
            $used = (string) DB::table('customer_credit_movements')->where('customer_credit_id', $creditId)->sum('amount');
            $remaining = bcsub((string) $credit->amount, $used, 2);
            if (bccomp($amount, $remaining, 2) > 0) {
                $this->fail('Số tiền vượt số dư được hoàn hoặc cấn trừ.');
            }
            $offsetDebt = null;
            if ($type === 'refund') {
                if ($debtId || ! in_array($method, ['cash', 'bank_transfer'], true)) {
                    $this->fail('Cần chọn phương thức hoàn tiền hợp lệ.');
                }
            } else {
                $debt = DB::table('debts')->where('id', $debtId)->lockForUpdate()->first();
                if (! $debt || $debt->direction !== 'receivable' || $debt->customer_id !== $credit->customer_id
                    || bccomp($amount, bcsub((string) $debt->amount, (string) $debt->paid, 2), 2) > 0) {
                    $this->fail('Chỉ được cấn trừ vào công nợ còn phải thu của cùng khách hàng.');
                }
                if ($debt->order_id) {
                    $order = DB::table('orders')->where('id', $debt->order_id)->lockForUpdate()->first();
                    $contract = $order?->contract_id ? DB::table('contracts')->where('id', $order->contract_id)->first() : null;
                    if (! $order || in_array($order->status, ['awaiting_confirmation', 'awaiting_contract', 'rejected', 'cancelled'], true)
                        || ($order->requires_contract && $contract?->status !== 'signed')) {
                        $this->fail('Đơn hàng chưa có hợp đồng đã ký hoặc đã kết thúc.');
                    }
                }
                $paid = bcadd((string) $debt->paid, $amount, 2);
                DB::table('debts')->where('id', $debtId)->update([
                    'paid' => $paid, 'status' => bccomp($paid, (string) $debt->amount, 2) >= 0 ? 'settled' : 'open',
                    'updated_at' => now(),
                ]);
                $offsetDebt = $debt;
            }
            $id = DB::table('customer_credit_movements')->insertGetId([
                'customer_credit_id' => $creditId, 'debt_id' => $type === 'offset' ? $debtId : null,
                'recorded_by' => $accountantId, 'type' => $type, 'amount' => $amount,
                'method' => $type === 'refund' ? $method : null, 'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($type === 'offset') {
                app(PaymentScheduleWorkflow::class)->allocateCustomerCredit($id);
                if ($offsetDebt?->order_id) {
                    $order = DB::table('orders')->where('id', $offsetDebt->order_id)->lockForUpdate()->first();
                    $changes = ['paid' => bcadd((string) $offsetDebt->paid, $amount, 2), 'updated_at' => now()];
                    if ($order?->status === 'awaiting_payment'
                        && app(PaymentScheduleWorkflow::class)->isReleaseEligible((int) $order->id)) {
                        $changes['status'] = 'ready_to_issue';
                    }
                    if ($order) {
                        DB::table('orders')->where('id', $order->id)->update($changes);
                    }
                }
            }
            if (bccomp($amount, $remaining, 2) === 0) {
                DB::table('customer_credits')->where('id', $creditId)->update(['status' => 'settled', 'updated_at' => now()]);
            }

            return $id;
        });
    }

    public function issueInvoice(int $orderId, int $accountantId): int
    {
        return DB::transaction(function () use ($orderId, $accountantId) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'ready_to_issue') {
                $this->fail('Đơn hàng chưa đủ điều kiện lập hóa đơn.');
            }
            $contract = DB::table('contracts')->where('id', $order->contract_id)->first();
            if ($order->requires_contract && $contract?->status !== 'signed') {
                $this->fail('Cần có hợp đồng đã ký trước khi lập hóa đơn.');
            }
            if (! app(PaymentScheduleWorkflow::class)->isReleaseEligible($orderId)) {
                $this->fail('Đơn hàng chưa được thanh toán đủ theo điều kiện xuất hàng.');
            }
            if (DB::table('invoices')->where('order_id', $orderId)->exists()) {
                $this->fail('Đơn hàng đã có hóa đơn.');
            }
            $id = DB::table('invoices')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'order_id' => $orderId, 'issued_by' => $accountantId,
                'total' => $order->total, 'paid_at_issue' => $order->paid,
                'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('invoices')->where('id', $id)->update(['code' => $this->code('HDON', $id)]);
            $this->releaseOrder($orderId, $accountantId);

            return $id;
        });
    }

    public function scheduleDebt(int $debtId, string $dueDate, string $note): void
    {
        if ($dueDate < now()->toDateString() || trim($note) === '') {
            $this->fail('Ngày quyết toán phải từ hôm nay trở đi và có ghi chú đối chiếu.');
        }
        $debt = DB::table('debts')->where('id', $debtId)->first();
        if (! $debt || $debt->status !== 'open' || bccomp((string) $debt->paid, (string) $debt->amount, 2) >= 0) {
            $this->fail('Công nợ đã tất toán hoặc không tồn tại.');
        }
        DB::table('debts')->where('id', $debtId)->update([
            'due_on' => $dueDate, 'settlement_note' => trim($note), 'updated_at' => now(),
        ]);
    }

    public function settleSupplierReturn(int $supplierReturnId, int $debtId, int $accountantId, string $credit, string $note): void
    {
        DB::transaction(function () use ($supplierReturnId, $debtId, $accountantId, $credit, $note) {
            $return = DB::table('supplier_returns')->where('id', $supplierReturnId)->lockForUpdate()->first();
            $debt = DB::table('debts')->where('id', $debtId)->lockForUpdate()->first();
            if (! $return || $return->settled_at || ! $debt || $debt->supplier_id !== $return->supplier_id
                || $debt->direction !== 'payable' || ! preg_match('/^\d+(\.\d{1,2})?$/', $credit)
                || bccomp($credit, '0', 2) <= 0
                || trim($note) === '') {
                $this->fail('Bù trừ nhà cung cấp không hợp lệ.');
            }
            $newAmount = bcsub((string) $debt->amount, $credit, 2);
            if (bccomp($newAmount, (string) $debt->paid, 2) < 0) {
                $this->fail('Tiền bù trừ vượt số công nợ còn phải trả.');
            }
            DB::table('debts')->where('id', $debtId)->update([
                'amount' => $newAmount,
                'status' => bccomp($newAmount, (string) $debt->paid, 2) === 0 ? 'settled' : 'open',
                'updated_at' => now(),
            ]);
            DB::table('supplier_returns')->where('id', $supplierReturnId)->update([
                'debt_id' => $debtId, 'settled_by' => $accountantId, 'credit_amount' => $credit,
                'settlement_note' => trim($note), 'settled_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function releaseOrder(int $orderId, int $accountantId): void
    {
        if (! DB::table('invoices')->where('order_id', $orderId)->exists()) {
            $this->fail('Cần lập hóa đơn trước khi phát lệnh xuất hàng.');
        }
        if (! app(PaymentScheduleWorkflow::class)->isReleaseEligible($orderId)) {
            $this->fail('Các đợt thanh toán bắt buộc trước khi xuất chưa được thanh toán đủ.');
        }
        $changed = DB::table('orders')->where('id', $orderId)->where('status', 'ready_to_issue')
            ->whereNull('released_by')->update([
                'released_by' => $accountantId, 'released_at' => now(), 'updated_at' => now(),
            ]);
        if (! $changed) {
            $this->fail('Đơn hàng chưa đủ điều kiện hoặc đã phát lệnh xuất hàng.');
        }
    }
}
