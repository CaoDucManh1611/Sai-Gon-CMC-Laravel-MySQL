@extends('layouts.store')
@section('title', 'Trợ lý AI | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro">
        <div class="eyebrow muted">Tư vấn vật liệu</div>
        <h1 class="page-title">Trợ lý VLXD CMC</h1>
        <p class="page-subtitle">Tra cứu hướng dẫn và sản phẩm CMC. Giá và khối lượng cần được nhân viên xác nhận trong báo giá.</p>
    </div>
    <div class="row pb-5"><div class="col-lg-8">
        <form method="post" action="{{ route('assistant.ask') }}" class="order-card">
            @csrf
            <label class="form-label" for="ai-question">Bạn cần tư vấn điều gì?</label>
            <textarea id="ai-question" class="form-control w-100" name="question" rows="5" minlength="3" maxlength="1000" required>{{ old('question', $question ?? '') }}</textarea>
            <button class="btn-editorial dark mt-3" type="submit">Gửi câu hỏi</button>
        </form>
        @isset($reply)
            <div class="order-card mt-3" role="status">
                <h2 class="h5">Trợ lý CMC</h2>
                <p style="white-space:pre-line">{{ $reply }}</p>
                @foreach($products ?? [] as $product)
                    <article class="chat-product-card mb-3" style="max-width:340px">
                        <a href="{{ $product['url'] }}"><span class="material-image {{ $product['image_class'] }}" role="img" aria-label="Hình minh họa {{ $product['name'] }}"></span></a>
                        <div class="chat-product-body">
                            <h3>{{ $product['name'] }}</h3><p>{{ $product['specification'] }}</p>
                            <strong>{{ $product['price'] }}</strong><small>Giá tham khảo · Hình minh họa</small>
                            <a href="{{ $product['url'] }}" class="chat-product-link">Xem chi tiết sản phẩm →</a>
                        </div>
                    </article>
                @endforeach
                @if(!empty($sources))
                    <p class="fw-bold">Nguồn tham khảo</p>
                    @foreach($sources as $index => $source)
                        <p><a href="{{ $source['url'] }}" target="_blank" rel="noopener">[{{ $index + 1 }}] {{ $source['title'] }} — {{ $source['section'] }}</a></p>
                    @endforeach
                @endif
            </div>
        @endisset
    </div></div>
</div>
@endsection
