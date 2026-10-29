<section class="sales-orders" aria-label="Quản lý đơn hàng">
    <div class="sales-section-heading">
        <div><span class="sales-kicker">TIẾP NHẬN VÀ THEO DÕI</span><h2>Đơn hàng của khách</h2></div>
        <span class="sales-result-count">{{ method_exists($salesOrders, 'total') ? $salesOrders->total() : $salesOrders->count() }} {{ method_exists($salesOrders, 'total') ? 'đơn tìm thấy' : 'đơn gần nhất' }}</span>
    </div>
    <form class="sales-order-filters" method="get" action="{{ route('staff.section', 'sales_orders') }}">
        <label>Tìm theo mã hoặc khách hàng<input class="form-control" name="q" value="{{ request('q') }}" placeholder="Mã đơn hoặc tên khách"></label>
        <label>Trạng thái<select class="form-select" name="status">
            <option value="">Tất cả trạng thái</option>
            @foreach(['awaiting_confirmation' => 'Chờ xác nhận', 'awaiting_contract' => 'Chờ lập hợp đồng', 'awaiting_payment' => 'Chờ thanh toán', 'ready_to_issue' => 'Chờ xuất kho', 'ready_to_ship' => 'Sẵn sàng giao', 'shipping' => 'Đang giao', 'delivery_incident' => 'Giao hàng gặp sự cố', 'delivered' => 'Đã giao', 'rejected' => 'Đã từ chối', 'cancelled' => 'Đã hủy'] as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </select></label>
        <button class="btn btn-dark">Áp dụng</button>
        <a class="sales-clear-filter" href="{{ route('staff.section', 'sales_orders') }}">Xóa lọc</a>
    </form>
    @forelse($salesOrders as $order)
        <details class="sales-order-card" id="order-{{ $order->id }}" @if(old('order_id') == $order->id && $errors->has('reason')) open @endif>
            <summary>
                <span><strong>{{ $order->code }}</strong><small>{{ $order->customer_name }} · {{ $order->fulfillment_method === 'pickup' ? 'Nhận tại kho' : 'Giao công trình' }}</small></span>
                <span class="sales-order-badge status-{{ $order->status }}">{{ \App\Support\UiFormat::status($order->status) }}</span>
                <strong class="sales-order-total">{{ number_format($order->total, 0, ',', '.') }} ₫</strong>
                <span class="sales-order-open">Chi tiết <svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3.5 6 4.5 4 4.5-4"/></svg></span>
            </summary>
            <div class="sales-order-detail">
                <dl><div><dt>Ngày đặt</dt><dd>{{ $order->created_at }}</dd></div><div><dt>Địa điểm</dt><dd>{{ $order->delivery_address }}</dd></div><div><dt>Đã thanh toán</dt><dd>{{ number_format($order->paid, 0, ',', '.') }} ₫</dd></div></dl>
                <div class="table-responsive"><table class="table"><thead><tr><th>Vật liệu</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead><tbody>
                    @foreach($salesOrderItems[$order->id] ?? [] as $item)
                        <tr><td>{{ $item->product_name }}</td><td>{{ \App\Support\UiFormat::quantity($item->quantity) }} {{ $item->unit }}</td><td>{{ number_format($item->unit_price, 0, ',', '.') }} ₫</td><td>{{ number_format($item->line_total, 0, ',', '.') }} ₫</td></tr>
                    @endforeach
                </tbody></table></div>
                @if($order->rejection_reason)<p class="sales-order-reason"><strong>Lý do từ chối:</strong> {{ $order->rejection_reason }}</p>@endif
                @if($order->quote_stock_note)<p class="sales-order-reason"><strong>Phản hồi thiếu tồn:</strong> {{ $order->quote_stock_note }}</p>@endif
                @if($order->stock_shortage_note)<p class="sales-order-reason"><strong>Đơn nhận tại kho thiếu tồn:</strong> {{ $order->stock_shortage_note }}. Dự kiến có hàng {{ \Carbon\Carbon::parse($order->estimated_available_on)->format('d/m/Y') }}. Kiểm tra tồn thực tế trước khi xác nhận; nếu chưa có hàng, liên hệ khách để thống nhất lịch hoặc vật liệu thay thế.</p>@endif
                @if($order->status === 'awaiting_confirmation' && auth()->user()->hasPermission('orders.review'))
                    <div class="sales-order-actions">
                        <form class="sales-order-confirm" method="post" action="{{ route('staff.orders.review', $order->id) }}">
                            @csrf
                            <input type="hidden" name="decision" value="confirm">
                            <p>{{ $order->requires_contract ? 'Xác nhận đơn để chuyển sang bước lập hợp đồng.' : 'Xác nhận đơn để khách thanh toán đủ trước khi xuất kho.' }}</p>
                            <button class="btn btn-dark">Xác nhận đơn</button>
                        </form>
                        <form class="sales-order-reject" method="post" action="{{ route('staff.orders.review', $order->id) }}">
                            @csrf
                            <input type="hidden" name="decision" value="reject"><input type="hidden" name="order_id" value="{{ $order->id }}">
                            <label for="rejection-reason-{{ $order->id }}">Lý do từ chối</label>
                            <div class="sales-order-reject-fields">
                                <input id="rejection-reason-{{ $order->id }}" class="form-control @if(old('order_id') == $order->id && $errors->has('reason')) is-invalid @endif" name="reason" value="{{ old('order_id') == $order->id ? old('reason') : '' }}" maxlength="2000" required aria-describedby="rejection-reason-error-{{ $order->id }}" placeholder="Giải thích để khách hàng biết">
                                <button class="btn btn-outline-danger">Từ chối đơn</button>
                            </div>
                            <div id="rejection-reason-error-{{ $order->id }}" class="invalid-feedback d-block">@if($errors->has('reason') && old('order_id') == $order->id){{ $errors->first('reason') }}@endif</div>
                        </form>
                    </div>
                @endif
                @if($order->requires_contract && $order->status === 'awaiting_contract' && auth()->user()->hasPermission('contracts.create'))
                    @if(!$order->draft_contract_code)
                        <form method="post" action="{{ route('staff.contracts.create', $order->id) }}" class="mt-3">
                            @csrf
                            <h3 class="h5">Lập hợp đồng từ đơn {{ $order->code }}</h3>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">Điều khoản thanh toán<textarea class="form-control" name="payment_terms" required></textarea></label></div>
                                <div class="col-md-6"><label class="form-label">Điều khoản giao hàng<textarea class="form-control" name="delivery_terms" required></textarea></label></div>
                            </div>
                            @include('staff.partials.payment-installment-editor', ['scheduleTotal' => $order->total, 'editorId' => 'new-contract-schedule-'.$order->id])
                            <button class="btn btn-dark mt-3">Lập hợp đồng</button>
                        </form>
                    @else
                        <p class="mt-3 mb-0">Hợp đồng đã được lập. <a href="{{ route('staff.section', ['section' => 'contracts', 'contract' => $order->draft_contract_code]) }}">Xem hợp đồng</a>.</p>
                    @endif
                @endif
            </div>
        </details>
    @empty
        <div class="sales-empty">Chưa có đơn hàng phù hợp.</div>
    @endforelse
    @if(method_exists($salesOrders, 'links')){{ $salesOrders->links() }}@endif
</section>
