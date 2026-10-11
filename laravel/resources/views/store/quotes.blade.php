@extends('layouts.store')
@section('title','Báo giá của tôi | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro">
        <div class="breadcrumbs"><a href="{{ route('home') }}">Trang chủ</a> / Báo giá</div>
        <h1 class="page-title">Báo giá của tôi</h1>
        <p class="page-subtitle">Theo dõi yêu cầu và báo giá đã được nhân viên gửi lại.</p>
    </div>
    @forelse($quotes as $quote)
        <article class="order-card quote-card">
            <div class="order-head">
                <div><a href="{{ route('my.quote',$quote->id) }}"><strong>{{ $quote->code }}</strong></a><div class="muted small mt-2">{{ $quote->created_at }} · {{ $quote->delivery_address }}</div></div>
                <span class="status status-{{ $quote->status }}">{{ \App\Support\UiFormat::status($quote->status) }}</span>
            </div>
            <div class="order-body">
                <div class="order-products">
                    @foreach($items[$quote->id] ?? [] as $item)
                        <a class="order-product" href="{{ route('product',$item->product_id) }}"><span class="material-image {{ $item->image_path }}" role="img" aria-label="Ảnh minh họa {{ $item->name }}"></span><span>{{ $item->name }} × {{ \App\Support\UiFormat::quantity($item->quantity) }}</span></a>
                    @endforeach
                </div>
                <div class="order-total"><strong>{{ number_format($quote->total,0,',','.') }} ₫</strong><a class="quote-view" href="{{ route('my.quote',$quote->id) }}">Xem báo giá</a></div>
            </div>
            @if($quote->status === 'requested')
                <div class="quote-actions">
                    <a class="btn btn-outline-dark btn-sm" href="{{ route('my.quote', ['id' => $quote->id, 'edit' => 1]) }}#chinh-sua">Sửa yêu cầu</a>
                    <form method="post" action="{{ route('my.quote.withdraw',$quote->id) }}" onsubmit="return confirm('Rút yêu cầu báo giá này? Lịch sử vẫn được lưu.');">@csrf<button class="btn btn-outline-danger btn-sm" type="submit">Rút yêu cầu</button></form>
                </div>
            @endif
        </article>
    @empty
        <div class="empty-state"><h2>Chưa có yêu cầu báo giá</h2><a class="btn-editorial dark" href="{{ route('catalog') }}">Chọn vật liệu</a></div>
    @endforelse
    <div class="py-4">{{ $quotes->links() }}</div>
</div>
@endsection
