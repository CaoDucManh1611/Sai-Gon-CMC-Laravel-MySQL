@php
    $quotesToReview = $quotes->where('status', 'pending_discount_approval');
    $contractsToReview = $contracts->where('status', 'pending_approval');
    $purchasesToReview = $purchases->where('status', 'pending');
    $openDebts = $debts->filter(fn ($debt) => $debt->status === 'open' && $debt->paid < $debt->amount && ($debt->direction === 'payable' || (!in_array($debt->order_status, ['rejected', 'cancelled'], true) && (!$debt->requires_contract || $debt->contract_status === 'signed'))));
@endphp
<section class="manager-overview" aria-label="Tổng quan quản lý">
    <div class="manager-heading"><div><span class="manager-kicker">CẦN QUẢN LÝ XỬ LÝ</span><h2>Việc chờ phê duyệt</h2></div></div>
    <div class="manager-metrics">
        <a href="{{ route('staff.section', 'quotes') }}" class="manager-metric"><span>Giá bán cần duyệt</span><strong>{{ $quotesToReview->count() }}</strong><small>Xem báo giá →</small></a>
        <a href="{{ route('staff.section', 'contracts') }}" class="manager-metric"><span>Hợp đồng cần duyệt</span><strong>{{ $contractsToReview->count() }}</strong><small>Xem hợp đồng →</small></a>
        <a href="{{ route('staff.section', 'purchases') }}" class="manager-metric"><span>Đề xuất nhập hàng</span><strong>{{ $purchasesToReview->count() }}</strong><small>Xem nhập hàng →</small></a>
        <a href="{{ route('staff.section', 'finance') }}" class="manager-metric"><span>Công nợ chưa tất toán</span><strong>{{ $openDebts->count() }}</strong><small>Xem công nợ →</small></a>
    </div>
    <div class="manager-lower">
        <section class="manager-card"><div class="manager-card-heading"><div><span class="manager-kicker">PHÊ DUYỆT</span><h2>Hồ sơ cần xem</h2></div></div>
            @foreach($quotesToReview->take(2) as $quote)
                <a class="manager-row" href="{{ route('staff.section', 'quotes') }}"><span><strong>{{ $quote->code }}</strong><small>Báo giá chờ duyệt giá</small></span><b>Xem →</b></a>
            @endforeach
            @foreach($contractsToReview->take(2) as $contract)
                <a class="manager-row" href="{{ route('staff.section', 'contracts') }}"><span><strong>{{ $contract->code }}</strong><small>Hợp đồng chờ duyệt</small></span><b>Xem →</b></a>
            @endforeach
            @foreach($purchasesToReview->take(2) as $purchase)
                <a class="manager-row" href="{{ route('staff.section', 'purchases') }}"><span><strong>{{ $purchase->code }}</strong><small>Đề xuất nhập hàng chờ duyệt</small></span><b>Xem →</b></a>
            @endforeach
            @if($quotesToReview->isEmpty() && $contractsToReview->isEmpty() && $purchasesToReview->isEmpty())<p class="manager-empty">Không có hồ sơ chờ phê duyệt.</p>@endif
        </section>
        <section class="manager-card manager-card-accent"><div class="manager-card-heading"><div><span class="manager-kicker">TÀI CHÍNH</span><h2>Công nợ cần theo dõi</h2></div><a href="{{ route('staff.section', 'finance') }}">Xem tất cả →</a></div>
            @forelse($openDebts->take(5) as $debt)
                <div class="manager-row"><span><strong>{{ $debt->code }}</strong><small>{{ $debt->direction === 'receivable' ? 'Phải thu' : 'Phải trả' }}</small></span><b>{{ number_format(max(0, $debt->amount - $debt->paid), 0, ',', '.') }} ₫</b></div>
            @empty<p class="manager-empty">Không có công nợ chưa tất toán.</p>@endforelse
        </section>
    </div>
</section>
