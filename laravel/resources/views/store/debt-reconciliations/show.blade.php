@extends('layouts.store')
@section('title', 'Chi tiết đối chiếu công nợ | Sài Gòn CMC')
@section('content')
<main class="container container-wide py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1>Đối chiếu công nợ · v{{ $reconciliation->version_number }}</h1><p class="text-secondary mb-0">Kỳ {{ $reconciliation->period_start }} – {{ $reconciliation->as_of_date }} · {{ \App\Support\UiFormat::status($reconciliation->status) }}</p></div><a class="btn btn-outline-dark" href="{{ route('my.debt-reconciliations.index') }}">Các bản đối chiếu</a></div>
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <div class="account-card mb-4"><h2 class="h5">Bảng đối chiếu</h2><div class="table-responsive"><table class="table"><thead><tr><th>Dư đầu kỳ</th><th>Phát sinh</th><th>Điều chỉnh trả hàng</th><th>Đã thu</th><th>Tiền thừa chuyển thành khoản khách được hưởng</th><th>Cấn trừ</th><th>Hủy nghĩa vụ</th><th>Dư cuối kỳ</th></tr></thead><tbody><tr>
        <td>{{ number_format($reconciliation->opening_balance, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_charges, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_returns, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_collected, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_credit_issued, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_offsets, 0, ',', '.') }} ₫</td><td>{{ number_format($reconciliation->period_cancellations, 0, ',', '.') }} ₫</td><td><strong>{{ number_format($reconciliation->closing_balance, 0, ',', '.') }} ₫</strong></td>
    </tr></tbody></table></div><p class="small text-secondary mb-0">Snapshot được tạo lúc {{ $snapshot['snapshot_at'] }}. Bản đã gửi được lưu nguyên trạng.</p>
    @if($reconciliation->previous_id)<p class="small mt-2 mb-0">Bản này được lập sau phiên bản v{{ $reconciliation->version_number - 1 }}.</p>@endif
    </div>

    <h2 class="h5">Từng khoản và giao dịch</h2>
    @forelse($snapshot['debts'] as $debt)<details class="account-card mb-3" open><summary><strong>{{ $debt['debt_code'] }}</strong>@if($debt['order_code']) · Đơn {{ $debt['order_code'] }}@endif · Còn {{ number_format($debt['closing_balance'], 0, ',', '.') }} ₫</summary><div class="table-responsive pt-3"><table class="table table-sm"><thead><tr><th>Ngày</th><th>Chứng từ</th><th>Nội dung</th><th>Giá trị</th></tr></thead><tbody>@forelse($debt['transactions'] as $event)<tr><td>{{ $event['date'] }}</td><td>{{ $event['reference'] }}</td><td>{{ $event['description'] }}</td><td>{{ number_format($event['amount'], 0, ',', '.') }} ₫</td></tr>@empty<tr><td colspan="4">Số dư được chuyển từ đầu kỳ.</td></tr>@endforelse</tbody></table></div></details>
    @empty<div class="account-card text-secondary">Không có khoản phải thu hợp lệ trong kỳ này.</div>@endforelse

    <section class="account-card mt-4"><h2 class="h5">Trao đổi</h2>
        @forelse($messages as $message)<article class="border rounded p-3 mb-2"><strong>{{ $message->author_name }}</strong> · {{ \App\Support\UiFormat::status($message->kind) }} <small class="text-secondary">{{ $message->created_at }}</small>@if($message->content)<p class="mb-0 mt-2">{{ $message->content }}</p>@endif</article>@empty<p class="text-secondary">Chưa có phản hồi.</p>@endforelse
        @if($reconciliation->status === 'sent')
            <form method="post" action="{{ route('my.debt-reconciliations.respond', $reconciliation->id) }}" class="mt-3">@csrf
                <label for="debt-response" class="form-label">Nếu có khoản chưa khớp, ghi rõ nội dung cần kiểm tra</label><textarea id="debt-response" class="form-control mb-3" name="message" maxlength="2000">{{ old('message') }}</textarea>
                <button class="btn btn-dark" name="decision" value="confirm">Xác nhận đúng</button>
                <button class="btn btn-outline-danger ms-2" name="decision" value="dispute">Báo chênh lệch</button>
            </form>
        @elseif($reconciliation->status === 'resolved' && ! $reconciliation->customer_confirmed_at)
            <p class="alert alert-info mt-3">Kế toán đã ghi cách xử lý chênh lệch. Việc này chưa được tính là bạn xác nhận; nếu bạn đồng ý với bản đối chiếu sau xử lý, hãy xác nhận bên dưới.</p>
            <form method="post" action="{{ route('my.debt-reconciliations.respond', $reconciliation->id) }}">@csrf<button class="btn btn-dark" name="decision" value="confirm">Xác nhận sau xử lý</button></form>
        @elseif($reconciliation->customer_confirmed_at)
            <p class="alert alert-success mt-3 mb-0">Đã có thao tác xác nhận của khách hàng lúc {{ $reconciliation->customer_confirmed_at }}.</p>
        @elseif($reconciliation->status === 'disputed')
            <p class="alert alert-warning mt-3 mb-0">Đã nhận nội dung chênh lệch; kế toán đang xử lý.</p>
        @endif
    </section>
</main>
@endsection
