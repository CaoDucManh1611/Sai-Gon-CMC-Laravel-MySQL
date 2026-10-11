@php
    $needsQuote = $quotes->whereIn('status', ['requested', 'draft']);
    $waitingApproval = $quotes->where('status', 'pending_discount_approval');
    $contractsToFollow = $contracts->whereIn('status', ['draft', 'changes_requested', 'pending_approval']);
    $productsToRestock = $quoteItems->only($quotes->pluck('id')->all())->flatten(1)
        ->filter(fn ($item) => (float) $item->available < (float) $item->quantity)
        ->groupBy('product_id')->take(4);
@endphp
<section class="sales-overview" aria-label="Công việc kinh doanh">
    <div class="sales-section-heading"><div><span class="sales-kicker">ƯU TIÊN HÔM NAY</span><h2>Công việc cần theo dõi</h2></div><span class="sales-section-note">Dữ liệu từ 30 hồ sơ gần nhất</span></div>
    <div class="sales-metrics">
        <a href="{{ route('staff.section', ['section' => 'quotes', 'status' => 'actionable']) }}" class="sales-metric"><span>Cần lập báo giá</span><strong>{{ $needsQuote->count() }}</strong><small>Xem yêu cầu <span aria-hidden="true">→</span></small></a>
        <a href="{{ route('staff.section', ['section' => 'quotes', 'status' => 'pending_discount_approval']) }}" class="sales-metric"><span>Chờ duyệt giá</span><strong>{{ $waitingApproval->count() }}</strong><small>Theo dõi báo giá <span aria-hidden="true">→</span></small></a>
        <a href="{{ route('staff.section', 'contracts') }}" class="sales-metric"><span>Hợp đồng cần theo dõi</span><strong>{{ $contractsToFollow->count() }}</strong><small>Xem hợp đồng <span aria-hidden="true">→</span></small></a>
    </div>
    <div class="sales-overview-lower">
        <section class="sales-recent"><div class="sales-section-heading"><div><span class="sales-kicker">HỒ SƠ GẦN ĐÂY</span><h2>Báo giá mới nhất</h2></div><a href="{{ route('staff.section', 'quotes') }}">Xem tất cả →</a></div>
            @forelse($quotes->take(5) as $quote)
                <a class="sales-recent-row" href="{{ route('staff.section', 'quotes') }}#quote-{{ $quote->id }}"><span><strong>{{ $quote->code }}</strong><small>{{ $quote->customer_name }}</small></span><span class="sales-badge sales-badge--{{ $quote->status }}">{{ \App\Support\UiFormat::status($quote->status) }}</span><strong>{{ number_format($quote->total, 0, ',', '.') }} ₫</strong></a>
            @empty<p class="sales-empty">Chưa có yêu cầu báo giá.</p>@endforelse
        </section>
        <aside class="sales-next-step"><span class="sales-kicker">CẦN KIỂM TRA TỒN KHO</span><h2>Vật liệu cần bổ sung</h2>
            @forelse($productsToRestock as $productItems)
                <a href="{{ route('staff.section', 'quotes') }}#quote-{{ $productItems->first()->quote_id }}"><span>{{ $productItems->first()->product_name }}<small>{{ $productItems->pluck('quote_id')->unique()->count() }} yêu cầu liên quan</small></span><span aria-hidden="true">→</span></a>
            @empty<p class="sales-stock-empty">Hiện chưa có vật liệu thiếu tồn trong các báo giá gần đây.</p>@endforelse
        </aside>
    </div>
</section>
