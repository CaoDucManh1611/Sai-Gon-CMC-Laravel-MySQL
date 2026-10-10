@extends('layouts.store')
@section('title', $product->name.' | Sài Gòn CMC')

@section('content')
<div class="container-fluid container-wide product-detail-page">
    <nav class="breadcrumbs" aria-label="Đường dẫn trang">
        <a href="{{ route('home') }}">Trang chủ</a><span aria-hidden="true">/</span>
        <a href="{{ route('catalog') }}">Sản phẩm</a><span aria-hidden="true">/</span>
        <span aria-current="page">{{ $product->name }}</span>
    </nav>

    <div class="product-detail-grid">
        <div class="product-visual">
            <div class="product-gallery" aria-label="Hình minh họa {{ $product->name }}">
                <div class="product-gallery-stage">
                    <div class="product-gallery-slide is-active" data-gallery-panel><span class="material-image {{ $product->image_path }}" role="img" aria-label="Hình minh họa {{ $product->name }}"></span></div>
                    <div class="product-gallery-slide product-gallery-closeup" data-gallery-panel hidden><span class="material-image {{ $product->image_path }}" role="img" aria-label="Góc nhìn gần của hình minh họa {{ $product->name }}"></span></div>
                    <div class="product-gallery-slide" data-gallery-panel hidden><img src="{{ asset('store/assets/hero-scene-1.webp') }}" alt="Công trình xây dựng minh họa bối cảnh sử dụng vật liệu" loading="lazy"></div>
                    <span class="product-gallery-counter" data-gallery-counter>01 / 03</span>
                </div>
                <div class="product-gallery-thumbs" role="group" aria-label="Chọn hình minh họa">
                    <button type="button" class="is-active" data-gallery-view="0" aria-label="Xem hình vật liệu" aria-pressed="true"><span class="material-image {{ $product->image_path }}" aria-hidden="true"></span></button>
                    <button type="button" data-gallery-view="1" aria-label="Xem góc nhìn gần" aria-pressed="false"><span class="material-image {{ $product->image_path }}" aria-hidden="true"></span></button>
                    <button type="button" data-gallery-view="2" aria-label="Xem bối cảnh công trình" aria-pressed="false"><img src="{{ asset('store/assets/hero-scene-1.webp') }}" alt="" loading="lazy"></button>
                </div>
            </div>
            <div class="product-visual-caption"><span>Hình minh họa · thông số thực tế theo xác nhận của nhân viên</span><span>{{ $categoryName }}</span></div>
        </div>

        <div class="product-summary">
            <div class="eyebrow muted">{{ $categoryName }} <span class="product-code">/ {{ $product->code }}</span></div>
            <h1>{{ $product->name }}</h1>
            @if($product->specification)<p class="product-summary-spec">{{ $product->specification }}</p>@endif

            <div class="product-price-panel">
                <span>Giá tham khảo · đã gồm VAT</span>
                <div><strong>{{ number_format($product->price, 0, ',', '.') }} ₫</strong><small>/ {{ $product->unit }}</small></div>
            </div>

            <div class="product-at-a-glance" aria-label="Thông tin nhanh">
                <div><span>Quy cách</span><strong>{{ $product->specification ?: 'Liên hệ tư vấn' }}</strong></div>
                <div><span>Đơn vị tính</span><strong>{{ $product->unit }}</strong></div>
            </div>

            <form class="product-purchase" action="{{ route('cart.add') }}" method="post">
                @csrf
                @php($fractional = in_array($product->unit, ['m³', 'kg'], true))
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <input type="hidden" name="replace" value="1">
                <label for="quantity">Số lượng <span>({{ $product->unit }})</span></label>
                <div class="product-purchase-row">
                    <input class="form-control" id="quantity" type="number" name="quantity" min="{{ $fractional ? '0.001' : '1' }}" max="1000000" step="{{ $fractional ? '0.001' : '1' }}" value="{{ old('quantity', session('cart', [])[$product->id] ?? 1) }}" required inputmode="{{ $fractional ? 'decimal' : 'numeric' }}">
                    <button class="btn-editorial dark" type="submit">Lưu số lượng vào giỏ</button>
                </div>
            </form>
            <p class="product-purchase-note">Số lượng bạn nhập sẽ là số lượng trong giỏ; chọn lại sẽ thay thế số cũ.</p>

            <div class="product-detail-links">
                <button type="button" data-bs-toggle="offcanvas" data-bs-target="#productSpecs" aria-controls="productSpecs">Xem mô tả và thông số</button>
                <a href="{{ route('estimate') }}">Dự toán vật liệu cho công trình</a>
            </div>
        </div>
    </div>

    <section class="product-editorial" aria-labelledby="productEditorialTitle">
        <div class="product-editorial-copy">
            <span class="eyebrow muted">Chọn đúng cho công trình</span>
            <h2 id="productEditorialTitle">Hiểu vật liệu trước khi đặt.</h2>
            <p>{{ $product->description ?: 'Mỗi công trình cần đúng loại vật liệu và đúng quy cách. Nhân viên Sài Gòn CMC sẽ kiểm tra nhu cầu, khối lượng và khả năng cung ứng trước khi xác nhận đơn.' }}</p>
            <dl><div><dt>Quy cách</dt><dd>{{ $product->specification ?: 'Liên hệ tư vấn' }}</dd></div><div><dt>Đơn vị tính</dt><dd>{{ $product->unit }}</dd></div><div><dt>Mã vật liệu</dt><dd>{{ $product->code }}</dd></div></dl>
        </div>
        <div class="product-editorial-photo"><img src="{{ asset('store/assets/craft.png') }}" alt="Công trình xây dựng minh họa quy trình thi công" loading="lazy"></div>
    </section>

    <section class="product-steps" aria-labelledby="productStepsTitle">
        <div><div class="eyebrow muted">Từ lựa chọn đến công trình</div><h2 id="productStepsTitle">Mua vật liệu rõ từng bước.</h2></div>
        <ol>
            <li><span>01</span><strong>Gửi nhu cầu</strong><p>Chọn vật liệu, số lượng rồi yêu cầu báo giá.</p></li>
            <li><span>02</span><strong>Chốt báo giá</strong><p>Nhân viên xác nhận giá, tồn kho và điều khoản hợp đồng.</p></li>
            <li><span>03</span><strong>Giao đến công trình</strong><p>Sau khi hợp đồng được duyệt, kho và giao nhận xử lý theo tiến độ.</p></li>
        </ol>
    </section>

    @if($related->isNotEmpty())
    <section class="product-related" aria-labelledby="relatedTitle">
        <div class="section-head"><div><div class="eyebrow muted">Cùng nhóm vật liệu</div><h2 id="relatedTitle">Xem thêm sản phẩm</h2></div><a class="text-link" href="{{ route('catalog', $categoryCode ? ['category' => $categoryCode] : []) }}">Xem cả danh mục</a></div>
        <div class="recommend-rail">@foreach($related as $relatedProduct)<div>@include('store._card', ['product' => $relatedProduct])</div>@endforeach</div>
    </section>
    @endif
</div>

<div class="offcanvas offcanvas-end detail-drawer" tabindex="-1" id="productSpecs" aria-labelledby="productSpecsTitle">
    <div class="offcanvas-header"><h2 class="offcanvas-title" id="productSpecsTitle">{{ $product->name }}</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Đóng"></button></div>
    <div class="offcanvas-body"><p class="muted">Thông tin vật liệu và quy cách tham khảo trước khi yêu cầu báo giá.</p><dl class="spec-list"><div><dt>Mã sản phẩm</dt><dd>{{ $product->code }}</dd></div><div><dt>Nhóm hàng</dt><dd>{{ $categoryName }}</dd></div><div><dt>Quy cách</dt><dd>{{ $product->specification ?: 'Liên hệ tư vấn' }}</dd></div><div><dt>Đơn vị tính</dt><dd>{{ $product->unit }}</dd></div><div><dt>Mô tả</dt><dd>{{ $product->description ?: 'Nhân viên sẽ tư vấn thêm khi nhận yêu cầu báo giá.' }}</dd></div></dl></div>
</div>
@endsection
