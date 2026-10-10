@extends('layouts.store')

@section('title', 'Sài Gòn CMC — Vật liệu xây dựng')

@section('content')
<section class="hero">
    <div class="hero-media" aria-hidden="true"><span class="hero-frame"></span><span class="hero-frame"></span><span class="hero-frame"></span></div>
    <div class="container-fluid container-wide hero-inner">
        <div class="eyebrow">Vật liệu cho mọi công trình</div>
        <h1>Vững từ<br>nền móng.</h1>
        <p>Chọn đúng vật liệu, xây chắc tương lai. Xem quy cách, gửi yêu cầu báo giá và theo dõi từng bước giao hàng.</p>
        <div class="hero-actions"><a class="btn-editorial" href="{{ route('catalog') }}">Khám phá sản phẩm</a><a class="btn-editorial outline" href="{{ route('estimate') }}">Dự toán vật liệu</a></div>
    </div>
</section>

<section class="section-space">
    <div class="container-fluid container-wide">
        <div class="section-head"><div><div class="eyebrow muted">Khám phá theo nhu cầu</div><h2>Vật liệu bạn đang tìm</h2></div><a class="text-link" href="{{ route('catalog') }}">Xem tất cả sản phẩm</a></div>
        <div class="category-marquee" aria-label="Nhóm vật liệu">
            <div class="category-track">
            @foreach($categories as $category)
                <a class="category-card" href="{{ route('catalog', ['category' => $category->code]) }}"><span class="material-image {{ $categoryImages[$category->code] }}" role="img" aria-label="Minh họa {{ $category->name }}"></span><strong>{{ $category->name }}</strong><small>{{ $productCounts[$category->id] ?? 0 }} sản phẩm</small></a>
            @endforeach
            @foreach($categories as $category)
                <a class="category-card" href="{{ route('catalog', ['category' => $category->code]) }}" aria-hidden="true" tabindex="-1"><span class="material-image {{ $categoryImages[$category->code] }}" aria-hidden="true"></span><strong>{{ $category->name }}</strong><small>{{ $productCounts[$category->id] ?? 0 }} sản phẩm</small></a>
            @endforeach
            </div>
        </div>
    </div>
</section>

<section class="section-space section-warm">
    <div class="container-fluid container-wide">
        <div class="section-head"><div><div class="eyebrow muted">Được quan tâm nhiều</div><h2>Chọn cho công trình</h2></div><p>Vật liệu thiết yếu, rõ quy cách để bạn dễ so sánh và lên kế hoạch.</p></div>
        <div class="row g-4">@foreach($featured as $product)<div class="col-6 col-lg-3">@include('store._card')</div>@endforeach</div>
        <div class="text-center mt-5"><a class="btn-editorial dark" href="{{ route('catalog') }}">Xem toàn bộ vật liệu</a></div>
    </div>
</section>

<section class="editorial">
    <div class="row g-0">
        <div class="col-lg-6 editorial-image" role="img" aria-label="Thợ xây đặt gạch tại công trình"></div>
        <div class="col-lg-6 editorial-copy"><div class="eyebrow">Đúng vật liệu. Đúng kế hoạch.</div><h2>Mỗi công trình<br>đều bắt đầu từ<br>sự chuẩn bị tốt.</h2><p>Sài Gòn CMC giúp bạn dự toán, chọn vật liệu và gửi nhu cầu để nhân viên kiểm tra tồn kho, báo giá trước khi giao đến công trình.</p><a class="btn-editorial dark" href="{{ route('estimate') }}">Thử công cụ dự toán</a></div>
    </div>
</section>

<section class="section-space">
    <div class="container-fluid container-wide">
        <div class="row g-4">
            <div class="col-md-4 benefit"><span class="benefit-num">01 /</span><h3>Quy cách rõ ràng</h3><p>Kích thước, đơn vị tính và mô tả được trình bày tại từng sản phẩm để bạn dễ chọn.</p></div>
            <div class="col-md-4 benefit"><span class="benefit-num">02 /</span><h3>Báo giá theo nhu cầu</h3><p>Chọn vật liệu và số lượng, sau đó gửi yêu cầu để nhân viên xác nhận giá và khả năng cung ứng.</p></div>
            <div class="col-md-4 benefit"><span class="benefit-num">03 /</span><h3>Theo dõi từng bước</h3><p>Xem báo giá, hợp đồng và tiến trình giao nhận ngay trong tài khoản của bạn.</p></div>
        </div>
    </div>
</section>
@endsection
