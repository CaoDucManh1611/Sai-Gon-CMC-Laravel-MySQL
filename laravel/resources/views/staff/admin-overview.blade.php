@php
    $quotesToReview = $quotes->where('status', 'pending_discount_approval');
    $contractsToReview = $contracts->where('status', 'pending_approval');
    $purchasesToReview = $purchases->where('status', 'pending');
    $lowStock = $inventory->filter(fn ($stock) => (float) $stock->quantity < (float) $stock->minimum_stock);
@endphp
<section class="admin-overview" aria-label="Tổng quan quản trị viên">
    <div class="admin-heading"><div><span class="admin-kicker">ĐIỀU HÀNH HỆ THỐNG</span><h2>Việc cần xử lý</h2></div><span class="admin-muted">Dữ liệu từ các hồ sơ gần nhất</span></div>
    <div class="admin-metrics">
        <a href="{{ route('staff.section', 'quotes') }}" class="admin-metric"><span>Giá bán cần duyệt</span><strong>{{ $quotesToReview->count() }}</strong><small>Xem báo giá →</small></a>
        <a href="{{ route('staff.section', 'contracts') }}" class="admin-metric"><span>Hợp đồng cần duyệt</span><strong>{{ $contractsToReview->count() }}</strong><small>Xem hợp đồng →</small></a>
        <a href="{{ route('staff.section', 'purchases') }}" class="admin-metric"><span>Đề xuất nhập hàng</span><strong>{{ $purchasesToReview->count() }}</strong><small>Xem nhập hàng →</small></a>
        <a href="{{ route('management.index') }}#management-users" class="admin-metric"><span>Tài khoản nội bộ</span><strong>{{ $staffAccountCount }}</strong><small>Quản lý tài khoản →</small></a>
    </div>
    <div class="admin-lower">
        <section class="admin-card"><div class="admin-card-heading"><div><span class="admin-kicker">PHÊ DUYỆT</span><h2>Hồ sơ cần xem</h2></div></div>
            @foreach($quotesToReview->take(2) as $quote)<a class="admin-row" href="{{ route('staff.section', 'quotes') }}"><span><strong>{{ $quote->code }}</strong><small>Báo giá chờ duyệt giá</small></span><b>Xem →</b></a>@endforeach
            @foreach($contractsToReview->take(2) as $contract)<a class="admin-row" href="{{ route('staff.section', 'contracts') }}"><span><strong>{{ $contract->code }}</strong><small>Hợp đồng chờ duyệt</small></span><b>Xem →</b></a>@endforeach
            @foreach($purchasesToReview->take(2) as $purchase)<a class="admin-row" href="{{ route('staff.section', 'purchases') }}"><span><strong>{{ $purchase->code }}</strong><small>Đề xuất nhập hàng chờ duyệt</small></span><b>Xem →</b></a>@endforeach
            @if($quotesToReview->isEmpty() && $contractsToReview->isEmpty() && $purchasesToReview->isEmpty())<p class="admin-empty">Không có hồ sơ chờ phê duyệt.</p>@endif
        </section>
        <section class="admin-card admin-card-accent"><div class="admin-card-heading"><div><span class="admin-kicker">TỒN KHO</span><h2>Vật liệu cần bổ sung</h2></div><a href="{{ route('staff.section', 'inventory') }}">Xem tồn kho →</a></div>
            @forelse($lowStock->take(5) as $stock)
                <div class="admin-row"><span><strong>{{ $stock->product_name }}</strong><small>{{ $stock->warehouse_name }}</small></span><b>{{ \App\Support\UiFormat::quantity($stock->quantity) }} / {{ \App\Support\UiFormat::quantity($stock->minimum_stock) }}</b></div>
            @empty<p class="admin-empty">Không có vật liệu dưới mức tồn tối thiểu.</p>@endforelse
        </section>
    </div>
</section>
