@php
    $openReceivables = $debts->filter(fn ($debt) => $debt->direction === 'receivable' && $debt->status === 'open' && $debt->paid < $debt->amount && !in_array($debt->order_status, ['awaiting_confirmation', 'awaiting_contract', 'rejected', 'cancelled'], true) && (!$debt->requires_contract || $debt->contract_status === 'signed'));
    $openPayables = $debts->filter(fn ($debt) => $debt->direction === 'payable' && $debt->status === 'open' && $debt->paid < $debt->amount);
    $pendingInvoices = $orders->filter(fn ($order) => $order->status === 'ready_to_issue' && !isset($invoices[$order->id]));
    $openCredits = $customerCredits->filter(fn ($credit) => $credit->status === 'open' && (float) $credit->amount > (float) ($creditUsed[$credit->id] ?? 0));
    $openDebts = $openReceivables->concat($openPayables)->sortByDesc('id');
@endphp
<section class="accounting-overview" aria-label="Tổng quan kế toán">
    <div class="accounting-heading"><div><span class="accounting-kicker">CÔNG VIỆC CẦN XỬ LÝ</span><h2>Tình hình kế toán</h2></div><span class="accounting-muted">Dữ liệu từ các hồ sơ gần nhất</span></div>
    <div class="accounting-metrics">
        <a href="{{ route('staff.section', 'finance') }}" class="accounting-metric"><span>Phải thu chưa tất toán</span><strong>{{ $openReceivables->count() }}</strong><small>Xem công nợ →</small></a>
        <a href="{{ route('staff.section', 'finance') }}" class="accounting-metric"><span>Phải trả chưa tất toán</span><strong>{{ $openPayables->count() }}</strong><small>Xem công nợ →</small></a>
        <a href="{{ route('staff.section', 'orders') }}" class="accounting-metric"><span>Hóa đơn cần lập</span><strong>{{ $pendingInvoices->count() }}</strong><small>Xem đơn hàng →</small></a>
        <a href="{{ route('staff.section', 'finance') }}" class="accounting-metric"><span>Khoản cần hoàn / cấn trừ</span><strong>{{ $openCredits->count() }}</strong><small>Xem khoản cần xử lý →</small></a>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-4">
        @if(auth()->user()->hasPermission('debts.manage'))<a class="btn btn-outline-dark btn-sm" href="{{ route('staff.debt-reconciliations.index') }}">Lập / xem đối chiếu công nợ khách</a>@endif
        @if(auth()->user()->hasPermission('supplier.confirmations.manage'))<a class="btn btn-outline-dark btn-sm" href="{{ route('staff.supplier-debt-confirmations.index') }}">Chứng từ xác nhận NCC</a>@endif
    </div>
    <div class="accounting-lower">
        <section class="accounting-card"><div class="accounting-card-heading"><div><span class="accounting-kicker">CÔNG NỢ</span><h2>Khoản chưa tất toán</h2></div><a href="{{ route('staff.section', 'finance') }}">Xem tất cả →</a></div>
            @forelse($openDebts->take(5) as $debt)
                <div class="accounting-row"><span><strong>{{ $debt->code }}</strong><small>{{ $debt->direction === 'receivable' ? 'Phải thu' : 'Phải trả' }}@if($debt->due_on) · Hẹn {{ $debt->due_on }}@endif</small></span><b>{{ number_format(max(0, $debt->amount - $debt->paid), 0, ',', '.') }} ₫</b></div>
            @empty<p class="accounting-empty">Không có công nợ chưa tất toán.</p>@endforelse
        </section>
        <section class="accounting-card accounting-card-accent"><div class="accounting-card-heading"><div><span class="accounting-kicker">HÓA ĐƠN</span><h2>Hóa đơn cần lập</h2></div><a href="{{ route('staff.section', 'orders') }}">Xem đơn hàng →</a></div>
            @forelse($pendingInvoices->take(5) as $order)
                <div class="accounting-row"><span><strong>{{ $order->code }}</strong><small>Sẵn sàng xuất kho</small></span><b>{{ number_format($order->total, 0, ',', '.') }} ₫</b></div>
            @empty<p class="accounting-empty">Không có hóa đơn cần lập.</p>@endforelse
        </section>
    </div>
</section>
