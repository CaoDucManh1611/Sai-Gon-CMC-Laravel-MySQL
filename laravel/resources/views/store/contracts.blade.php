@extends('layouts.store')
@section('title','Hợp đồng của tôi | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro"><h1 class="page-title">Hợp đồng của tôi</h1><p class="page-subtitle">Xem điều khoản sau khi Ban quản lý phê duyệt. Chỉ xác nhận khi thông tin và giá trị đã đúng.</p></div>
    @forelse($contracts as $contract)
        <article class="order-card">
            <div class="order-head"><strong>{{ $contract->code }}</strong><span class="status">{{ $contract->status === 'approved' && !$contract->customer_confirmed_at ? 'Chờ bạn xác nhận' : \App\Support\UiFormat::status($contract->status) }}</span></div>
            <div class="row g-3"><div class="col-md-4"><span class="muted">Giá trị</span><strong class="d-block">{{ number_format($contract->total,0,',','.') }} ₫</strong></div><div class="col-md-4"><span class="muted">Đặt cọc yêu cầu</span><strong class="d-block">{{ number_format($contract->deposit_required,0,',','.') }} ₫</strong></div><div class="col-md-4"><span class="muted">Ngày lập</span><strong class="d-block">{{ $contract->created_at }}</strong></div></div>
            <div class="mt-3"><strong>Điều khoản thanh toán</strong><p>{{ $contract->payment_terms }}</p><strong>Điều khoản giao hàng</strong><p>{{ $contract->delivery_terms }}</p></div>
            @if($contract->customer_note)<p class="muted">Ý kiến của bạn: {{ $contract->customer_note }}</p>@endif
            @if($contract->cancellation_note)<p class="muted">Lý do hủy: {{ $contract->cancellation_note }}</p>@endif
            @if($contract->status === 'approved' && !$contract->customer_confirmed_at)
                <div class="d-flex flex-wrap gap-3">
                    <form method="post" action="{{ route('my.contracts.respond',$contract->id) }}">@csrf<input type="hidden" name="decision" value="accept"><button class="btn-editorial dark" type="submit">Xác nhận nội dung hợp đồng</button></form>
                    <form method="post" action="{{ route('my.contracts.respond',$contract->id) }}" class="d-flex flex-wrap gap-2">@csrf<input type="hidden" name="decision" value="revise"><label class="visually-hidden" for="note-{{ $contract->id }}">Nội dung cần sửa</label><input class="form-control" id="note-{{ $contract->id }}" name="note" placeholder="Nội dung cần sửa" maxlength="2000" required><button class="btn btn-outline-dark" type="submit">Yêu cầu sửa</button></form>
                </div>
            @elseif($contract->customer_confirmed_at && $contract->status === 'approved')<p class="muted">Bạn đã xác nhận nội dung. Nhân viên sẽ hoàn tất bản ký và chuyển sang thanh toán.</p>@endif
        </article>
    @empty<div class="empty-state"><h2>Chưa có hợp đồng</h2><p>Hợp đồng sẽ xuất hiện sau khi bạn chấp nhận báo giá.</p><a class="btn-editorial dark" href="{{ route('my.quotes') }}">Xem báo giá</a></div>@endforelse
</div>
@endsection
