@php
    $plannedShipments = $shipments->where('status', 'planned');
    $shippingShipments = $shipments->where('status', 'shipping');
    $incidentShipments = $shipments->where('status', 'incident');
    $deliveredShipments = $shipments->where('status', 'delivered');
    $activeShipments = $shipments->filter(fn ($shipment) => in_array($shipment->status, ['planned', 'shipping', 'incident'], true));
@endphp
<section class="delivery-overview" aria-label="Tổng quan giao nhận">
    <div class="delivery-heading"><div><span class="delivery-kicker">CÔNG VIỆC GIAO NHẬN</span><h2>Chuyến hàng cần theo dõi</h2></div><span class="delivery-muted">Dữ liệu từ các chuyến gần nhất</span></div>
    <div class="delivery-metrics">
        <a href="{{ route('staff.section', 'orders') }}" class="delivery-metric"><span>Chờ nhận hàng</span><strong>{{ $plannedShipments->count() }}</strong><small>Xem chuyến hàng →</small></a>
        <a href="{{ route('staff.section', 'orders') }}" class="delivery-metric"><span>Đang giao</span><strong>{{ $shippingShipments->count() }}</strong><small>Xem chuyến hàng →</small></a>
        <a href="{{ route('staff.section', 'orders') }}" class="delivery-metric"><span>Cần xử lý sự cố</span><strong>{{ $incidentShipments->count() }}</strong><small>Xem sự cố →</small></a>
        <a href="{{ route('staff.section', 'orders') }}" class="delivery-metric"><span>Đã giao gần đây</span><strong>{{ $deliveredShipments->count() }}</strong><small>Xem đơn hàng →</small></a>
    </div>
    <div class="delivery-lower">
        <section class="delivery-card"><div class="delivery-card-heading"><div><span class="delivery-kicker">ƯU TIÊN XỬ LÝ</span><h2>Chuyến đang thực hiện</h2></div><a href="{{ route('staff.section', 'orders') }}">Xem tất cả →</a></div>
            @forelse($activeShipments->take(5) as $shipment)
                <div class="delivery-row"><span><strong>{{ $shipment->code }}</strong><small>Đơn #{{ $shipment->order_id }} · {{ \App\Support\UiFormat::status($shipment->status) }}</small></span><b>{{ $shipment->vehicle ?: 'Chưa có xe' }}</b></div>
            @empty<p class="delivery-empty">Không có chuyến hàng cần xử lý.</p>@endforelse
        </section>
        <section class="delivery-card delivery-card-accent"><div class="delivery-card-heading"><div><span class="delivery-kicker">LỘ TRÌNH</span><h2>Chuyến giao gần đây</h2></div><a href="{{ route('staff.section', 'orders') }}">Xem lộ trình →</a></div>
            @forelse($deliveryRuns->take(5) as $run)
                <div class="delivery-row"><span><strong>{{ $run->code }}</strong><small>{{ $run->carrier_name }}</small></span><b>{{ $run->vehicle }}</b></div>
            @empty<p class="delivery-empty">Chưa có chuyến giao nhiều đơn.</p>@endforelse
        </section>
    </div>
</section>
