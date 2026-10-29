<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfillmentWorkflow
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }

    private function code(string $prefix, int $id): string
    {
        return $prefix.'-'.now()->format('Y').'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private function moveStock(int $documentId, int $warehouseId, int $productId, string $change): void
    {
        DB::table('inventory')->insertOrIgnore([
            'warehouse_id' => $warehouseId, 'product_id' => $productId,
            'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stock = DB::table('inventory')->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)->lockForUpdate()->first();
        $after = bcadd((string) $stock->quantity, $change, 3);
        if (bccomp($after, '0', 3) < 0) {
            $this->fail('Không đủ tồn kho thực tế để xuất hàng.');
        }
        DB::table('inventory')->where('id', $stock->id)->update(['quantity' => $after, 'updated_at' => now()]);
        DB::table('stock_movements')->insert([
            'stock_document_id' => $documentId, 'warehouse_id' => $warehouseId,
            'product_id' => $productId, 'change' => $change, 'balance_after' => $after,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function proposeStocktake(int $warehouseId, int $productId, int $staffId, string $counted, string $reason): int
    {
        return DB::transaction(function () use ($warehouseId, $productId, $staffId, $counted, $reason) {
            if (! is_numeric($counted) || bccomp($counted, '0', 3) < 0 || trim($reason) === '') {
                $this->fail('Số lượng kiểm kê hoặc lý do không hợp lệ.');
            }
            $book = DB::table('inventory')->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)->lockForUpdate()->value('quantity') ?? '0';
            if (bccomp((string) $book, $counted, 3) === 0) {
                $this->fail('Không có chênh lệch cần điều chỉnh.');
            }
            $id = DB::table('stock_documents')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'type' => 'stocktake', 'warehouse_id' => $warehouseId,
                'created_by' => $staffId, 'status' => 'pending', 'note' => trim($reason),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('stock_documents')->where('id', $id)->update(['code' => $this->code('KK', $id)]);
            DB::table('stock_document_items')->insert([
                'stock_document_id' => $id, 'product_id' => $productId, 'quantity' => $counted,
                'book_quantity' => $book, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    public function reviewStocktake(int $documentId, int $managerId, bool $approve): void
    {
        DB::transaction(function () use ($documentId, $managerId, $approve) {
            $document = DB::table('stock_documents')->where('id', $documentId)->lockForUpdate()->first();
            if (! $document || $document->type !== 'stocktake' || $document->status !== 'pending') {
                $this->fail('Biên bản kiểm kê không còn chờ duyệt.');
            }
            app(ApprovalWorkflow::class)->assertIndependent((int) $document->created_by, $managerId, 'biên bản kiểm kê');
            $item = DB::table('stock_document_items')->where('stock_document_id', $documentId)->first();
            $content = ['document' => (array) $document, 'items' => $item ? [(array) $item] : []];
            if ($approve) {
                $book = DB::table('inventory')->where('warehouse_id', $document->warehouse_id)
                    ->where('product_id', $item->product_id)->lockForUpdate()->value('quantity') ?? '0';
                if (bccomp((string) $book, (string) $item->book_quantity, 3) !== 0) {
                    $this->fail('Tồn kho đã thay đổi sau kiểm kê. Cần đếm lại.');
                }
                $this->moveStock($documentId, $document->warehouse_id, $item->product_id,
                    bcsub((string) $item->quantity, (string) $book, 3));
            }
            DB::table('stock_documents')->where('id', $documentId)->update([
                'status' => $approve ? 'approved' : 'rejected', 'approved_by' => $managerId, 'updated_at' => now(),
            ]);
            app(ApprovalWorkflow::class)->recordDecision('stocktake', $documentId, $approve ? 'approved' : 'rejected',
                'pending', $approve ? 'approved' : 'rejected', $managerId, null, $content);
        });
    }

    public function createPurchaseOrder(int $supplierId, int $warehouseId, int $staffId, array $items): int
    {
        return DB::transaction(function () use ($supplierId, $warehouseId, $staffId, $items) {
            if ($items === [] || ! DB::table('suppliers')->where('id', $supplierId)->where('active', true)->exists()
                || ! DB::table('warehouses')->where('id', $warehouseId)->where('active', true)->exists()) {
                $this->fail('Nhà cung cấp, kho hoặc danh sách hàng không hợp lệ.');
            }
            $id = DB::table('purchase_orders')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId,
                'requested_by' => $staffId, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $total = '0.00';
            foreach ($items as $item) {
                $productId = $item['product_id'] ?? null;
                $quantity = (string) ($item['quantity'] ?? 0);
                $price = (string) ($item['unit_price'] ?? -1);
                if (! DB::table('products')->where('id', $productId)->exists()
                    || ! is_numeric($quantity) || bccomp($quantity, '0', 3) <= 0
                    || ! is_numeric($price) || bccomp($price, '0', 2) < 0) {
                    $this->fail('Dòng nhập hàng không hợp lệ.');
                }
                $total = bcadd($total, bcmul($quantity, $price, 2), 2);
                DB::table('purchase_items')->insert([
                    'purchase_order_id' => $id, 'product_id' => $productId,
                    'quantity' => $quantity, 'unit_price' => $price,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('purchase_orders')->where('id', $id)->update(['code' => $this->code('MH', $id), 'total' => $total]);

            return $id;
        });
    }

    public function approvePurchaseOrder(int $purchaseId, int $managerId): void
    {
        DB::transaction(function () use ($purchaseId, $managerId) {
            $purchase = DB::table('purchase_orders')->where('id', $purchaseId)->lockForUpdate()->first();
            if (! $purchase || $purchase->status !== 'pending') {
                $this->fail('Đề xuất nhập hàng không còn chờ duyệt.');
            }
            app(ApprovalWorkflow::class)->assertIndependent((int) $purchase->requested_by, $managerId, 'đề xuất nhập hàng');
            $content = [
                'purchase_order' => (array) $purchase,
                'items' => DB::table('purchase_items')->where('purchase_order_id', $purchaseId)->orderBy('id')->get()
                    ->map(fn ($item) => (array) $item)->all(),
            ];
            $changed = DB::table('purchase_orders')->where('id', $purchaseId)->where('status', 'pending')->update([
                'status' => 'approved', 'approved_by' => $managerId, 'updated_at' => now(),
            ]);
            if (! $changed) {
                $this->fail('Đề xuất nhập hàng không còn chờ duyệt.');
            }
            app(ApprovalWorkflow::class)->recordDecision('purchase_order', $purchaseId, 'approved',
                'pending', 'approved', $managerId, null, $content);
        });
    }

    public function receivePurchaseOrder(int $purchaseId, int $staffId, array $received): int
    {
        return DB::transaction(function () use ($purchaseId, $staffId, $received) {
            $purchase = DB::table('purchase_orders')->where('id', $purchaseId)->lockForUpdate()->first();
            if (! $purchase || $purchase->status !== 'approved') {
                $this->fail('Đơn nhập hàng phải được duyệt trước khi nhận.');
            }
            $lines = DB::table('purchase_items')->where('purchase_order_id', $purchaseId)->get();
            $documentId = DB::table('stock_documents')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'type' => 'receipt', 'warehouse_id' => $purchase->warehouse_id,
                'purchase_order_id' => $purchaseId, 'created_by' => $staffId, 'approved_by' => $staffId,
                'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('stock_documents')->where('id', $documentId)->update(['code' => $this->code('PN', $documentId)]);
            $actualTotal = '0.00';
            $hasReceived = false;
            $complete = true;
            foreach ($lines as $line) {
                $quantity = (string) ($received[$line->id] ?? 0);
                $remaining = bcsub((string) $line->quantity, (string) $line->received_quantity, 3);
                if (! is_numeric($quantity) || bccomp($quantity, '0', 3) < 0 || bccomp($quantity, $remaining, 3) > 0) {
                    $this->fail('Số lượng nhập thực tế vượt phiếu đặt hàng.');
                }
                if (bccomp($quantity, '0', 3) > 0) {
                    $hasReceived = true;
                    DB::table('stock_document_items')->insert([
                        'stock_document_id' => $documentId, 'product_id' => $line->product_id,
                        'quantity' => $quantity, 'unit_price' => $line->unit_price,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->moveStock($documentId, $purchase->warehouse_id, $line->product_id, $quantity);
                    $actualTotal = bcadd($actualTotal, bcmul($quantity, (string) $line->unit_price, 2), 2);
                    DB::table('purchase_items')->where('id', $line->id)->update([
                        'received_quantity' => bcadd((string) $line->received_quantity, $quantity, 3), 'updated_at' => now(),
                    ]);
                }
                if (bccomp($quantity, $remaining, 3) < 0) {
                    $complete = false;
                }
            }
            if (! $hasReceived) {
                $this->fail('Cần ghi nhận ít nhất một mặt hàng thực nhận.');
            }
            DB::table('purchase_orders')->where('id', $purchaseId)->update([
                'status' => $complete ? 'received' : 'approved', 'updated_at' => now(),
            ]);
            $exchanges = DB::table('returns')->where('restock_purchase_order_id', $purchaseId)
                ->where('status', 'inspected')->get();
            foreach ($exchanges as $exchange) {
                $available = (string) (DB::table('inventory')->where('warehouse_id', $exchange->replacement_warehouse_id)
                    ->where('product_id', $exchange->product_id)->value('quantity') ?? 0);
                if (bccomp($available, (string) $exchange->quantity, 3) >= 0) {
                    DB::table('returns')->where('id', $exchange->id)->update([
                        'estimated_available_on' => null, 'updated_at' => now(),
                    ]);
                }
            }
            $debtId = DB::table('debts')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'direction' => 'payable', 'supplier_id' => $purchase->supplier_id,
                'purchase_order_id' => $purchaseId, 'amount' => $actualTotal,
                'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('debts')->where('id', $debtId)->update(['code' => $this->code('CN', $debtId)]);

            return $documentId;
        });
    }

    public function reportSupplierIncident(int $purchaseId, int $itemId, int $staffId, string $type, string $quantity, string $note): int
    {
        return DB::transaction(function () use ($purchaseId, $itemId, $staffId, $type, $quantity, $note) {
            $purchase = DB::table('purchase_orders')->where('id', $purchaseId)->lockForUpdate()->first();
            $item = DB::table('purchase_items')->where('id', $itemId)->where('purchase_order_id', $purchaseId)->first();
            if (! $purchase || ! in_array($purchase->status, ['approved', 'received'], true) || ! $item
                || ! in_array($type, ['shortage', 'damaged'], true) || ! is_numeric($quantity)
                || bccomp($quantity, '0', 3) <= 0 || trim($note) === '') {
                $this->fail('Thông tin sự vụ nhà cung cấp không hợp lệ.');
            }
            $unreceived = bcsub((string) $item->quantity, (string) $item->received_quantity, 3);
            $reported = (string) DB::table('supplier_incidents')->where('purchase_item_id', $itemId)
                ->where('status', 'open')->sum('quantity');
            if (bccomp(bcadd($reported, $quantity, 3), $unreceived, 3) > 0) {
                $this->fail('Số lượng báo thiếu hoặc hỏng vượt phần chưa nhập kho.');
            }

            return DB::table('supplier_incidents')->insertGetId([
                'purchase_order_id' => $purchaseId, 'purchase_item_id' => $itemId,
                'recorded_by' => $staffId, 'type' => $type, 'quantity' => $quantity,
                'note' => trim($note), 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function resolveSupplierIncident(int $incidentId, int $staffId, string $resolution): void
    {
        if (trim($resolution) === '') {
            $this->fail('Cần ghi kết quả làm việc với nhà cung cấp.');
        }
        $changed = DB::table('supplier_incidents')->where('id', $incidentId)->where('status', 'open')->update([
            'status' => 'resolved', 'resolution_note' => trim($resolution),
            'resolved_by' => $staffId, 'resolved_at' => now(), 'updated_at' => now(),
        ]);
        if (! $changed) {
            $this->fail('Sự vụ không còn chờ xử lý.');
        }
    }

    public function issueOrder(int $orderId, int $warehouseId, int $staffId): int
    {
        return DB::transaction(function () use ($orderId, $warehouseId, $staffId) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'ready_to_issue') {
                $this->fail('Đơn hàng chưa đủ điều kiện xuất kho.');
            }
            if (! $order->released_by) {
                $this->fail('Kế toán chưa phát lệnh xuất hàng.');
            }
            DB::table('warehouses')->where('id', $warehouseId)->lockForUpdate()->first();
            if (($order->fulfillment_method === 'pickup' && $order->pickup_warehouse_id !== $warehouseId)
                || ! app(PaymentScheduleWorkflow::class)->isReleaseEligible($orderId)) {
                $this->fail('Kho nhận hàng hoặc số tiền đã thanh toán không hợp lệ.');
            }
            if (DB::table('stock_documents')->where('order_id', $orderId)->where('type', 'issue')->where('status', 'approved')->exists()) {
                $this->fail('Đơn hàng đã xuất kho.');
            }
            $items = DB::table('order_items')->where('order_id', $orderId)->get();
            $documentId = DB::table('stock_documents')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'type' => 'issue', 'warehouse_id' => $warehouseId,
                'order_id' => $orderId, 'created_by' => $staffId, 'approved_by' => $staffId,
                'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('stock_documents')->where('id', $documentId)->update(['code' => $this->code('PX', $documentId)]);
            foreach ($items as $item) {
                $reserved = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.pickup_warehouse_id', $warehouseId)
                    ->whereIn('orders.status', ['awaiting_contract', 'awaiting_payment', 'ready_to_issue'])
                    ->where('orders.id', '!=', $orderId)
                    ->where('order_items.product_id', $item->product_id)->sum('order_items.quantity');
                $available = DB::table('inventory')->where('warehouse_id', $warehouseId)
                    ->where('product_id', $item->product_id)->value('quantity') ?? 0;
                if (bccomp(bcsub((string) $available, (string) $reserved, 3), (string) $item->quantity, 3) < 0) {
                    $this->fail('Tồn kho đã dành cho đơn nhận tại kho khác; không thể xuất hàng.');
                }
                $this->moveStock($documentId, $warehouseId, $item->product_id, '-'.(string) $item->quantity);
                DB::table('stock_document_items')->insert([
                    'stock_document_id' => $documentId, 'product_id' => $item->product_id,
                    'quantity' => $item->quantity, 'unit_price' => $item->unit_price,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('orders')->where('id', $orderId)->update(['status' => 'ready_to_ship', 'updated_at' => now()]);

            return $documentId;
        });
    }

    public function planShipment(int $orderId, ?int $carrierId, string $carrierName, string $vehicle, string $routeNote): int
    {
        return DB::transaction(function () use ($orderId, $carrierId, $carrierName, $vehicle, $routeNote) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'ready_to_ship' || $order->fulfillment_method === 'pickup'
                || DB::table('shipments')->where('order_id', $orderId)->exists()) {
                $this->fail('Đơn hàng chưa xuất kho hoặc đã lên chuyến.');
            }
            $id = DB::table('shipments')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'order_id' => $orderId, 'carrier_id' => $carrierId,
                'carrier_name' => $carrierName, 'vehicle' => $vehicle, 'route_note' => $routeNote,
                'status' => 'planned', 'planned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('shipments')->where('id', $id)->update(['code' => $this->code('GN', $id)]);

            return $id;
        });
    }

    public function handoverPickup(int $orderId, int $staffId, string $recipient): void
    {
        if (trim($recipient) === '') {
            $this->fail('Cần ghi tên người nhận hàng tại kho.');
        }
        DB::transaction(function () use ($orderId, $staffId, $recipient) {
            $changed = DB::table('orders')->where('id', $orderId)->where('fulfillment_method', 'pickup')
                ->where('status', 'ready_to_ship')->update([
                    'status' => 'delivered', 'pickup_recipient' => trim($recipient),
                    'picked_up_by' => $staffId, 'picked_up_at' => now(), 'updated_at' => now(),
                ]);
            if (! $changed) {
                $this->fail('Đơn hàng chưa xuất kho hoặc đã bàn giao.');
            }
            app(PaymentScheduleWorkflow::class)->activateHandoverTerms($orderId);
        });
    }

    public function pickupShipment(int $shipmentId, int $staffId, string $receiver): void
    {
        if (trim($receiver) === '') {
            $this->fail('Cần ghi tên người nhận hàng của đơn vị vận chuyển.');
        }
        DB::transaction(function () use ($shipmentId, $staffId, $receiver) {
            $shipment = DB::table('shipments')->where('id', $shipmentId)->lockForUpdate()->first();
            if (! $shipment || $shipment->status !== 'planned') {
                $this->fail('Chuyến giao hàng không còn chờ nhận hàng.');
            }
            DB::table('shipments')->where('id', $shipmentId)->update([
                'status' => 'shipping', 'pickup_by' => $staffId, 'pickup_receiver' => trim($receiver),
                'picked_up_at' => now(), 'updated_at' => now(),
            ]);
            if (! $shipment->return_id) {
                $changed = DB::table('orders')->where('id', $shipment->order_id)
                    ->whereIn('status', ['ready_to_ship', 'delivery_incident'])
                    ->update(['status' => 'shipping', 'updated_at' => now()]);
                if (! $changed) {
                    $this->fail('Đơn hàng không còn chờ giao.');
                }
            }
        });
    }

    public function groupDeliveryRun(array $shipmentIds, int $staffId, string $carrier, string $vehicle, string $routeNote, ?string $capacityKg = null, array $stopOrder = [], ?array $origin = null, array $coordinates = [], ?string $capacityM3 = null): int
    {
        return DB::transaction(function () use ($shipmentIds, $staffId, $carrier, $vehicle, $routeNote, $capacityKg, $stopOrder, $origin, $coordinates, $capacityM3) {
            $ids = array_values(array_unique(array_map('intval', $shipmentIds)));
            if (count($ids) < 2 || trim($carrier) === '' || trim($vehicle) === '' || trim($routeNote) === '') {
                $this->fail('Lộ trình cần ít nhất hai đơn và đủ thông tin vận chuyển.');
            }
            $shipments = DB::table('shipments')->whereIn('id', $ids)->lockForUpdate()->get();
            if ($shipments->count() !== count($ids) || $shipments->contains(fn ($item) => $item->status !== 'planned' || $item->delivery_run_id !== null || $item->return_id !== null)) {
                $this->fail('Có chuyến đã nhận hàng, thuộc lộ trình khác hoặc là hàng đổi trả.');
            }
            $loadKg = null;
            $loadM3 = null;
            if ($capacityKg !== null || $capacityM3 !== null) {
                $weights = DB::table('shipments')->join('order_items', 'order_items.order_id', '=', 'shipments.order_id')
                    ->join('products', 'products.id', '=', 'order_items.product_id')
                    ->whereIn('shipments.id', $ids)->select('order_items.quantity', 'products.weight_kg', 'products.volume_m3')->get();
            }
            if ($capacityKg !== null) {
                if (! is_numeric($capacityKg) || bccomp($capacityKg, '0', 3) <= 0) {
                    $this->fail('Tải trọng xe phải lớn hơn 0 kg.');
                }
                if ($weights->contains(fn ($item) => $item->weight_kg === null)) {
                    $this->fail('Chưa có khối lượng của một số vật liệu; cần cập nhật trước khi kiểm tra tải trọng.');
                }
                $loadKg = '0.000';
                foreach ($weights as $item) {
                    $loadKg = bcadd($loadKg, bcmul((string) $item->quantity, (string) $item->weight_kg, 3), 3);
                }
                if (bccomp($loadKg, $capacityKg, 3) > 0) {
                    $this->fail('Tổng khối lượng '.$loadKg.' kg vượt tải trọng xe '.$capacityKg.' kg. Hãy tách chuyến hoặc đổi xe.');
                }
            }
            if ($capacityM3 !== null) {
                if (! is_numeric($capacityM3) || bccomp($capacityM3, '0', 4) <= 0) {
                    $this->fail('Thể tích chứa của xe phải lớn hơn 0 m³.');
                }
                if ($weights->contains(fn ($item) => $item->volume_m3 === null)) {
                    $this->fail('Chưa có thể tích của một số vật liệu; cần cập nhật trước khi kiểm tra độ cồng kềnh.');
                }
                $loadM3 = '0.0000';
                foreach ($weights as $item) {
                    $loadM3 = bcadd($loadM3, bcmul((string) $item->quantity, (string) $item->volume_m3, 4), 4);
                }
                if (bccomp($loadM3, $capacityM3, 4) > 0) {
                    $this->fail('Tổng thể tích '.$loadM3.' m³ vượt sức chứa xe '.$capacityM3.' m³. Hãy tách chuyến hoặc đổi xe.');
                }
            }
            $sequence = [];
            foreach ($ids as $shipmentId) {
                $position = $stopOrder[$shipmentId] ?? null;
                if ($stopOrder !== [] && (! is_numeric($position) || (int) $position < 1 || (float) $position !== (float) (int) $position)) {
                    $this->fail('Thứ tự điểm giao phải là số nguyên dương.');
                }
                $sequence[$shipmentId] = $stopOrder === [] ? array_search($shipmentId, $ids, true) + 1 : (int) $position;
            }
            if (count(array_unique(array_values($sequence))) !== count($ids)) {
                $this->fail('Các điểm giao phải có thứ tự khác nhau.');
            }
            if ($origin !== null) {
                $validPoint = static fn (array $point): bool => isset($point['lat'], $point['lon'])
                    && is_numeric($point['lat']) && is_numeric($point['lon'])
                    && abs((float) $point['lat']) <= 90 && abs((float) $point['lon']) <= 180;
                if (! $validPoint($origin)) {
                    $this->fail('Tọa độ xuất phát không hợp lệ.');
                }
                foreach ($ids as $shipmentId) {
                    if (! isset($coordinates[$shipmentId]) || ! is_array($coordinates[$shipmentId]) || ! $validPoint($coordinates[$shipmentId])) {
                        $this->fail('Cần nhập tọa độ hợp lệ cho mọi điểm giao được chọn.');
                    }
                }
                $remaining = $ids;
                $current = $origin;
                $sequence = [];
                while ($remaining !== []) {
                    usort($remaining, static function (int $first, int $second) use ($coordinates, $current): int {
                        $distance = static function (array $point) use ($current): float {
                            $lat1 = deg2rad((float) $current['lat']);
                            $lat2 = deg2rad((float) $point['lat']);
                            $deltaLat = $lat2 - $lat1;
                            $deltaLon = deg2rad((float) $point['lon'] - (float) $current['lon']);

                            return 2 * 6371 * asin(min(1, sqrt(sin($deltaLat / 2) ** 2
                                + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2)));
                        };

                        return $distance($coordinates[$first]) <=> $distance($coordinates[$second]);
                    });
                    $next = array_shift($remaining);
                    $sequence[$next] = count($sequence) + 1;
                    $current = $coordinates[$next];
                }
            }
            $id = DB::table('delivery_runs')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'created_by' => $staffId,
                'carrier_name' => trim($carrier), 'vehicle' => trim($vehicle),
                'route_note' => trim($routeNote), 'capacity_kg' => $capacityKg, 'load_kg' => $loadKg,
                'capacity_m3' => $capacityM3, 'load_m3' => $loadM3,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('delivery_runs')->where('id', $id)->update(['code' => $this->code('LT', $id)]);
            DB::table('shipments')->whereIn('id', $ids)->update([
                'delivery_run_id' => $id, 'carrier_name' => trim($carrier), 'vehicle' => trim($vehicle),
                'route_note' => trim($routeNote), 'updated_at' => now(),
            ]);
            foreach ($sequence as $shipmentId => $position) {
                DB::table('shipments')->where('id', $shipmentId)->update(['stop_sequence' => $position]);
            }

            return $id;
        });
    }

    public function rescheduleShipment(int $shipmentId, string $vehicle, string $routeNote): void
    {
        if (trim($vehicle) === '' || trim($routeNote) === '') {
            $this->fail('Cần ghi phương tiện và lịch trình giao lại.');
        }
        DB::transaction(function () use ($shipmentId, $vehicle, $routeNote) {
            $shipment = DB::table('shipments')->where('id', $shipmentId)->lockForUpdate()->first();
            if (! $shipment || $shipment->status !== 'incident') {
                $this->fail('Chỉ chuyến có sự cố mới được lên lịch giao lại.');
            }
            DB::table('shipments')->where('id', $shipmentId)->update([
                'status' => 'planned', 'vehicle' => trim($vehicle), 'route_note' => trim($routeNote),
                'pickup_by' => null, 'pickup_receiver' => null, 'picked_up_at' => null,
                'planned_at' => now(), 'updated_at' => now(),
            ]);
            if ($shipment->return_id) {
                DB::table('returns')->where('id', $shipment->return_id)->update(['status' => 'replacement_shipping', 'updated_at' => now()]);
            }
        });
    }

    public function handover(int $shipmentId, string $recipient, bool $accepted, string $conditionNote): void
    {
        DB::transaction(function () use ($shipmentId, $recipient, $accepted, $conditionNote) {
            $shipment = DB::table('shipments')->where('id', $shipmentId)->lockForUpdate()->first();
            if (! $shipment || $shipment->status !== 'shipping') {
                $this->fail('Đơn vị vận chuyển chưa nhận hàng hoặc chuyến đã bàn giao.');
            }
            DB::table('handovers')->insert([
                'shipment_id' => $shipmentId, 'recipient' => $recipient, 'accepted' => $accepted,
                'condition_note' => $conditionNote, 'signed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('shipments')->where('id', $shipmentId)->update([
                'status' => $accepted ? 'delivered' : 'incident',
                'delivered_at' => $accepted ? now() : null, 'updated_at' => now(),
            ]);
            if ($shipment->return_id) {
                DB::table('returns')->where('id', $shipment->return_id)->update([
                    'status' => $accepted ? 'completed' : 'replacement_incident', 'updated_at' => now(),
                ]);
            } else {
                DB::table('orders')->where('id', $shipment->order_id)->update([
                    'status' => $accepted ? 'delivered' : 'delivery_incident', 'updated_at' => now(),
                ]);
                if ($accepted) {
                    app(PaymentScheduleWorkflow::class)->activateHandoverTerms((int) $shipment->order_id);
                }
            }
        });
    }

    public function requestReturn(int $orderId, int $customerId, int $productId, string $quantity, string $reason, string $type = 'return'): int
    {
        return DB::transaction(function () use ($orderId, $customerId, $productId, $quantity, $reason, $type) {
            $order = DB::table('orders')->where('id', $orderId)->first();
            $item = DB::table('order_items')->where('order_id', $orderId)->where('product_id', $productId)->first();
            if (! $order || $order->customer_id !== $customerId || ! in_array($order->status, ['delivered', 'delivery_incident'], true) || ! $item
                || ! is_numeric($quantity) || bccomp($quantity, '0', 3) <= 0 || ! in_array($type, ['return', 'exchange'], true)) {
                $this->fail('Yêu cầu trả hàng không hợp lệ.');
            }
            $already = DB::table('returns')->where('order_id', $orderId)->where('product_id', $productId)
                ->whereNotIn('status', ['rejected'])->sum('quantity');
            if (bccomp(bcadd((string) $already, $quantity, 3), (string) $item->quantity, 3) > 0) {
                $this->fail('Số lượng trả vượt số lượng đã giao.');
            }
            $id = DB::table('returns')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'order_id' => $orderId, 'product_id' => $productId,
                'customer_id' => $customerId, 'quantity' => $quantity, 'reason' => $reason,
                'type' => $type, 'status' => 'requested',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('returns')->where('id', $id)->update(['code' => $this->code('DT', $id)]);

            return $id;
        });
    }

    public function collectReturn(int $returnId, int $staffId, string $handedOverBy, string $quantity, string $note): void
    {
        if (trim($handedOverBy) === '' || trim($note) === '' || ! is_numeric($quantity)) {
            $this->fail('Cần ghi đủ người bàn giao, số lượng và biên bản thu hồi.');
        }
        DB::transaction(function () use ($returnId, $staffId, $handedOverBy, $quantity, $note) {
            $return = DB::table('returns')->where('id', $returnId)->lockForUpdate()->first();
            if (! $return || $return->status !== 'requested' || bccomp($quantity, (string) $return->quantity, 3) !== 0) {
                $this->fail('Yêu cầu đổi trả không còn chờ thu hồi hoặc số lượng không khớp.');
            }
            DB::table('returns')->where('id', $returnId)->update([
                'status' => 'collected', 'collected_by' => $staffId, 'collected_at' => now(),
                'handed_over_by' => trim($handedOverBy), 'collected_quantity' => $quantity,
                'collection_note' => trim($note), 'updated_at' => now(),
            ]);
        });
    }

    public function inspectReturn(int $returnId, int $warehouseId, int $staffId, bool $reusable, string $note): void
    {
        DB::transaction(function () use ($returnId, $warehouseId, $staffId, $reusable, $note) {
            $return = DB::table('returns')->where('id', $returnId)->lockForUpdate()->first();
            if (! $return || $return->status !== 'collected') {
                $this->fail('Giao nhận chưa thu hồi hàng trả hoặc phiếu đã được kiểm tra.');
            }
            $item = DB::table('order_items')->where('order_id', $return->order_id)
                ->where('product_id', $return->product_id)->first();
            $credit = bcmul((string) $item->unit_price, (string) $return->quantity, 2);
            if ($reusable) {
                $documentId = DB::table('stock_documents')->insertGetId([
                    'code' => 'TEMP-'.uniqid(), 'type' => 'return', 'warehouse_id' => $warehouseId,
                    'order_id' => $return->order_id, 'created_by' => $staffId, 'approved_by' => $staffId,
                    'status' => 'approved', 'note' => $note,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('stock_documents')->where('id', $documentId)->update(['code' => $this->code('PT', $documentId)]);
                DB::table('stock_document_items')->insert([
                    'stock_document_id' => $documentId, 'product_id' => $return->product_id,
                    'quantity' => $return->quantity, 'unit_price' => $item->unit_price,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->moveStock($documentId, $warehouseId, $return->product_id, (string) $return->quantity);
            }
            DB::table('returns')->where('id', $returnId)->update([
                'status' => 'inspected', 'condition' => $reusable ? 'reusable' : 'damaged',
                'credit_amount' => $return->type === 'return' ? $credit : '0.00', 'processed_by' => $staffId,
                'inspection_note' => $note, 'replacement_warehouse_id' => $return->type === 'exchange' ? $warehouseId : null,
                'updated_at' => now(),
            ]);
            if ($return->type === 'exchange') {
                $available = (string) (DB::table('inventory')->where('warehouse_id', $warehouseId)
                    ->where('product_id', $return->product_id)->value('quantity') ?? 0);
                if (bccomp($available, (string) $return->quantity, 3) < 0) {
                    $supplier = DB::table('purchase_orders')->join('purchase_items', 'purchase_items.purchase_order_id', '=', 'purchase_orders.id')
                        ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
                        ->where('purchase_items.product_id', $return->product_id)->where('suppliers.active', true)
                        ->where('purchase_items.received_quantity', '>', 0)->orderByDesc('purchase_orders.id')
                        ->select('purchase_orders.supplier_id', 'purchase_items.unit_price')->first();
                    $purchaseId = $supplier ? $this->createPurchaseOrder($supplier->supplier_id, $warehouseId, $staffId, [[
                        'product_id' => $return->product_id,
                        'quantity' => bcsub((string) $return->quantity, $available, 3),
                        'unit_price' => $supplier->unit_price,
                    ]]) : null;
                    DB::table('returns')->where('id', $returnId)->update([
                        'restock_purchase_order_id' => $purchaseId,
                        'estimated_available_on' => now()->addDays(14)->toDateString(),
                    ]);
                    if ($purchaseId) {
                        DB::table('purchase_orders')->where('id', $purchaseId)->update([
                            'note' => 'Bổ sung hàng đổi theo phiếu '.$return->code,
                        ]);
                    }
                }
            }
        });
    }

    public function issueExchange(int $returnId, int $warehouseId, int $staffId, string $carrierName, string $vehicle, string $routeNote): int
    {
        return DB::transaction(function () use ($returnId, $warehouseId, $staffId, $carrierName, $vehicle, $routeNote) {
            $return = DB::table('returns')->where('id', $returnId)->lockForUpdate()->first();
            if (! $return || $return->type !== 'exchange' || $return->status !== 'inspected') {
                $this->fail('Yêu cầu đổi hàng chưa được kho kiểm tra hoặc đã xử lý.');
            }
            $documentId = DB::table('stock_documents')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'type' => 'exchange_issue', 'warehouse_id' => $warehouseId,
                'order_id' => $return->order_id, 'created_by' => $staffId, 'approved_by' => $staffId,
                'status' => 'approved', 'note' => 'Đổi hàng theo phiếu '.$return->code,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('stock_documents')->where('id', $documentId)->update(['code' => $this->code('PX', $documentId)]);
            $this->moveStock($documentId, $warehouseId, $return->product_id, '-'.(string) $return->quantity);
            DB::table('stock_document_items')->insert([
                'stock_document_id' => $documentId, 'product_id' => $return->product_id,
                'quantity' => $return->quantity, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $shipmentId = DB::table('shipments')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'order_id' => $return->order_id, 'return_id' => $returnId,
                'carrier_name' => $carrierName, 'vehicle' => $vehicle, 'route_note' => $routeNote,
                'status' => 'planned', 'planned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('shipments')->where('id', $shipmentId)->update(['code' => $this->code('GN', $shipmentId)]);
            DB::table('returns')->where('id', $returnId)->update([
                'status' => 'replacement_shipping', 'estimated_available_on' => null, 'updated_at' => now(),
            ]);

            return $shipmentId;
        });
    }

    public function requestExchangeRestock(int $returnId, int $supplierId, int $staffId, string $unitPrice): int
    {
        return DB::transaction(function () use ($returnId, $supplierId, $staffId, $unitPrice) {
            $return = DB::table('returns')->where('id', $returnId)->lockForUpdate()->first();
            if (! $return || $return->type !== 'exchange' || $return->status !== 'inspected'
                || ! $return->replacement_warehouse_id || $return->restock_purchase_order_id) {
                $this->fail('Phiếu đổi hàng không còn cần lập đề xuất nhập.');
            }
            $available = (string) (DB::table('inventory')->where('warehouse_id', $return->replacement_warehouse_id)
                ->where('product_id', $return->product_id)->value('quantity') ?? 0);
            if (bccomp($available, (string) $return->quantity, 3) >= 0) {
                $this->fail('Kho đã đủ hàng đổi; hãy xuất hàng thay thế.');
            }
            $purchaseId = $this->createPurchaseOrder($supplierId, $return->replacement_warehouse_id, $staffId, [[
                'product_id' => $return->product_id,
                'quantity' => bcsub((string) $return->quantity, $available, 3),
                'unit_price' => $unitPrice,
            ]]);
            DB::table('purchase_orders')->where('id', $purchaseId)->update(['note' => 'Bổ sung hàng đổi theo phiếu '.$return->code]);
            DB::table('returns')->where('id', $returnId)->update(['restock_purchase_order_id' => $purchaseId,
                'estimated_available_on' => now()->addDays(14)->toDateString(), 'updated_at' => now()]);

            return $purchaseId;
        });
    }

    public function sendDamagedReturnToSupplier(
        int $returnId,
        int $supplierId,
        int $staffId,
        string $note,
        bool $responsibilityConfirmed,
        string $responsibilityEvidence
    ): int
    {
        if (! $responsibilityConfirmed || trim($responsibilityEvidence) === '') {
            $this->fail('Cần xác nhận trách nhiệm nhà cung cấp và ghi căn cứ đối chiếu trước khi trả hàng.');
        }

        return DB::transaction(function () use ($returnId, $supplierId, $staffId, $note, $responsibilityEvidence) {
            $return = DB::table('returns')->where('id', $returnId)->lockForUpdate()->first();
            if (! $return || $return->condition !== 'damaged' || ! in_array($return->status, ['inspected', 'completed'], true)
                || trim($note) === '' || ! DB::table('suppliers')->where('id', $supplierId)->where('active', true)->exists()
                || ! DB::table('purchase_orders')->join('purchase_items', 'purchase_items.purchase_order_id', '=', 'purchase_orders.id')
                    ->where('purchase_orders.supplier_id', $supplierId)->where('purchase_items.product_id', $return->product_id)
                    ->where('purchase_items.received_quantity', '>', 0)->exists()
                || DB::table('supplier_returns')->where('return_id', $returnId)->exists()) {
                $this->fail('Chỉ được trả hàng hỏng cho nhà cung cấp đã giao vật liệu này và có phiếu nhập thực nhận.');
            }
            $id = DB::table('supplier_returns')->insertGetId([
                'code' => 'TEMP-'.uniqid(), 'return_id' => $returnId,
                'supplier_id' => $supplierId, 'recorded_by' => $staffId,
                'responsibility_confirmed_by' => $staffId, 'responsibility_confirmed_at' => now(),
                'responsibility_evidence' => trim($responsibilityEvidence),
                'quantity' => $return->quantity, 'note' => trim($note),
                'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('supplier_returns')->where('id', $id)->update(['code' => $this->code('TNCC', $id)]);

            return $id;
        });
    }

    public function settleReturn(int $returnId, int $accountantId): void
    {
        DB::transaction(function () use ($returnId, $accountantId) {
            $return = DB::table('returns')->where('id', $returnId)->lockForUpdate()->first();
            if (! $return || $return->status !== 'inspected' || $return->type !== 'return') {
                $this->fail('Hàng trả chưa được kho kiểm tra.');
            }
            $debt = DB::table('debts')->where('order_id', $return->order_id)->where('direction', 'receivable')
                ->lockForUpdate()->first();
            if (! $debt) {
                $this->fail('Không tìm thấy công nợ của đơn hàng.');
            }
            $newAmount = bcsub((string) $debt->amount, (string) $return->credit_amount, 2);
            if (bccomp($newAmount, '0', 2) < 0) {
                $this->fail('Giá trị trả hàng vượt giá trị đơn.');
            }
            if (bccomp((string) $return->credit_amount, '0', 2) > 0) {
                app(PaymentScheduleWorkflow::class)->adjustForReturn(
                    (int) $returnId,
                    (int) $debt->id,
                    $accountantId,
                    (string) $return->credit_amount,
                    'Giảm nghĩa vụ theo phiếu trả hàng '.$return->code
                );
            }
            $previousOverpaid = bccomp((string) $debt->paid, (string) $debt->amount, 2) > 0
                ? bcsub((string) $debt->paid, (string) $debt->amount, 2) : '0.00';
            $newOverpaid = bccomp((string) $debt->paid, $newAmount, 2) > 0
                ? bcsub((string) $debt->paid, $newAmount, 2) : '0.00';
            $overpaid = bcsub($newOverpaid, $previousOverpaid, 2);
            DB::table('debts')->where('id', $debt->id)->update([
                'amount' => $newAmount, 'status' => bccomp((string) $debt->paid, $newAmount, 2) >= 0 ? 'settled' : 'open',
                'updated_at' => now(),
            ]);
            if (bccomp($overpaid, '0', 2) > 0) {
                DB::table('customer_credits')->insert([
                    'customer_id' => $return->customer_id, 'return_id' => $returnId,
                    'amount' => $overpaid, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('returns')->where('id', $returnId)->update([
                'status' => 'completed', 'processed_by' => $accountantId,
                'completed_at' => now()->format('Y-m-d H:i:s.u'), 'updated_at' => now(),
            ]);
        });
    }
}
