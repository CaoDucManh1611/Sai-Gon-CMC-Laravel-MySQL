<section class="mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><h1 class="h3">Đối chiếu {{ $reconciliation->customer_name }} · v{{ $reconciliation->version_number }}</h1><p class="text-secondary mb-0">Kỳ {{ $reconciliation->period_start }} – {{ $reconciliation->as_of_date }} · {{ \App\Support\UiFormat::status($reconciliation->status) }}</p></div>
        <a class="btn btn-outline-dark" href="{{ route('staff.debt-reconciliations.index') }}">Danh sách đối chiếu</a>
    </div>
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif

    <div class="order-card mb-4">
        <h2 class="h5">Bảng tổng hợp tại thời điểm tạo snapshot</h2>
        <div class="table-responsive"><table class="table"><thead><tr><th>Dư đầu kỳ</th><th>Phát sinh</th><th>Điều chỉnh trả hàng</th><th>Đã thu</th><th>Tiền thừa chuyển thành khoản khách được hưởng</th><th>Cấn trừ</th><th>Hủy nghĩa vụ</th><th>Dư cuối kỳ</th></tr></thead><tbody><tr>
            <td>{{ number_format($reconciliation->opening_balance, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_charges, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_returns, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_collected, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_credit_issued, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_offsets, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_cancellations, 0, ',', '.') }} ₫</td><td><strong>{{ number_format($reconciliation->closing_balance, 0, ',', '.') }} ₫</strong></td>
        </tr></tbody></table></div>
        <p class="small text-secondary mb-0">Tạo lúc {{ $snapshot['snapshot_at'] }} · Mã kiểm tra snapshot: <code>{{ $reconciliation->snapshot_hash }}</code></p>
        @if($reconciliation->previous_id)<p class="small mb-0 mt-2">Phiên bản trước: <a href="{{ route('staff.debt-reconciliations.show', $reconciliation->previous_id) }}">v{{ $reconciliation->version_number - 1 }}</a></p>@endif
    </div>

    <h2 class="h5">Chi tiết công nợ và giao dịch trong kỳ</h2>
    @forelse($snapshot['debts'] as $debt)
        <details class="order-card mb-3" open><summary><strong>{{ $debt['debt_code'] }}</strong>@if($debt['order_code']) · Đơn {{ $debt['order_code'] }}@endif · Còn {{ number_format($debt['closing_balance'], 0, ',', '.') }} ₫</summary>
            <div class="table-responsive pt-3"><table class="table table-sm"><thead><tr><th>Ngày</th><th>Chứng từ</th><th>Nội dung</th><th>Giá trị</th></tr></thead><tbody>
                @forelse($debt['transactions'] as $event)<tr><td>{{ $event['date'] }}</td><td>{{ $event['reference'] }}</td><td>{{ $event['description'] }}</td><td>{{ number_format($event['amount'], 0, ',', '.') }} ₫</td></tr>
                @empty<tr><td colspan="4" class="text-secondary">Không có giao dịch trong kỳ; số dư được chuyển từ đầu kỳ.</td></tr>@endforelse
            </tbody></table></div>
        </details>
    @empty<div class="order-card text-secondary">Không có khoản phải thu hợp lệ trong kỳ này.</div>@endforelse

    <section class="order-card mt-4"><h2 class="h5">Phản hồi và xử lý</h2>
        @forelse($messages as $message)<article class="border rounded p-3 mb-2"><strong>{{ $message->author_name }}</strong> · {{ \App\Support\UiFormat::status($message->kind) }} <small class="text-secondary">{{ $message->created_at }}</small>@if($message->content)<p class="mb-0 mt-2">{{ $message->content }}</p>@endif</article>
        @empty<p class="text-secondary">Chưa có phản hồi.</p>@endforelse
        @if($reconciliation->status === 'draft')<form method="post" action="{{ route('staff.debt-reconciliations.send', $reconciliation->id) }}">@csrf<button class="btn btn-dark">Gửi cho khách hàng</button></form>@endif
        @if($reconciliation->status === 'disputed')<form method="post" action="{{ route('staff.debt-reconciliations.resolve', $reconciliation->id) }}" class="mt-3"><label for="resolution-message" class="form-label">Cách kế toán xử lý chênh lệch</label><textarea id="resolution-message" class="form-control mb-2" name="message" maxlength="4000" required>{{ old('message') }}</textarea><button class="btn btn-dark">Ghi nhận xử lý</button></form>@endif
        @if($reconciliation->status === 'resolved' && ! $reconciliation->customer_confirmed_at)<p class="alert alert-warning mt-3 mb-0">Kế toán đã ghi cách xử lý; khách hàng chưa thực hiện thao tác xác nhận.</p>@endif
    </section>
</section>
