@php
    $pendingPurchases = $purchases->where('status', 'pending');
    $readyToReceive = $purchases->where('status', 'approved');
    $ordersToHandle = $orders->whereIn('status', ['ready_to_issue', 'ready_to_ship']);
    $returnsToInspect = $returns->where('status', 'requested');
    $lowStock = $inventory->filter(fn ($stock) => (float) $stock->quantity < (float) $stock->minimum_stock);
@endphp
<section class="warehouse-overview" aria-label="Công việc kho">
    <div class="warehouse-heading"><div><span class="warehouse-kicker">CÔNG VIỆC HÔM NAY</span><h2>Việc cần xử lý</h2></div><span class="warehouse-muted">Dữ liệu từ các hồ sơ gần nhất</span></div>
    <div class="warehouse-metrics">
        <a href="{{ route('staff.section', 'purchases') }}" class="warehouse-metric"><span>Chờ duyệt nhập hàng</span><strong>{{ $pendingPurchases->count() }}</strong><small>Xem đề xuất →</small></a>
        <a href="{{ route('staff.section', 'purchases') }}" class="warehouse-metric"><span>Chờ nhận hàng</span><strong>{{ $readyToReceive->count() }}</strong><small>Xem đơn nhập →</small></a>
        <a href="{{ route('staff.section', 'orders') }}" class="warehouse-metric"><span>Đơn cần xuất / giao</span><strong>{{ $ordersToHandle->count() }}</strong><small>Xem đơn hàng →</small></a>
        <a href="{{ route('staff.section', 'returns') }}" class="warehouse-metric"><span>Yêu cầu đổi trả mới</span><strong>{{ $returnsToInspect->count() }}</strong><small>Xem đổi trả →</small></a>
    </div>
    <div class="warehouse-overview-lower">
        <section class="warehouse-overview-card"><div class="warehouse-heading"><div><span class="warehouse-kicker">NHẬP HÀNG</span><h2>Đề xuất gần đây</h2></div><a href="{{ route('staff.section', 'purchases') }}">Xem tất cả →</a></div>
            @forelse($purchases->take(5) as $purchase)
                <div class="warehouse-recent-row"><strong>{{ $purchase->code }}</strong><span>{{ \App\Support\UiFormat::status($purchase->status) }}</span><b>{{ number_format($purchase->total, 0, ',', '.') }} ₫</b></div>
            @empty<p class="warehouse-empty">Chưa có đề xuất nhập hàng.</p>@endforelse
        </section>
        <section class="warehouse-overview-card warehouse-overview-alert"><div class="warehouse-heading"><div><span class="warehouse-kicker">TỒN KHO</span><h2>Vật liệu dưới mức tối thiểu</h2></div><a href="{{ route('staff.section', 'inventory') }}">Xem tồn kho →</a></div>
            @forelse($lowStock->take(5) as $stock)
                <div class="warehouse-recent-row"><span><strong>{{ $stock->product_name }}</strong><small>{{ $stock->warehouse_name }}</small></span><b>{{ \App\Support\UiFormat::quantity($stock->quantity) }} / {{ \App\Support\UiFormat::quantity($stock->minimum_stock) }}</b></div>
            @empty<p class="warehouse-empty">Không có vật liệu dưới mức tồn tối thiểu.</p>@endforelse
        </section>
    </div>
</section>
