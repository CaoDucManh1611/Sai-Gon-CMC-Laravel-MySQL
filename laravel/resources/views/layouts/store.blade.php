<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f6f4ef"><script>try{document.documentElement.dataset.theme=localStorage.getItem('cmc-theme')==='dark'?'dark':'light'}catch{document.documentElement.dataset.theme='light'}document.documentElement.dataset.bsTheme=document.documentElement.dataset.theme</script><title>@yield('title', 'Sài Gòn CMC — Vật liệu xây dựng')</title><link rel="icon" href="{{ asset('store/favicon.svg') }}"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600&display=swap" rel="stylesheet"><link href="{{ asset('store/bootstrap.min.css') }}" rel="stylesheet"><link href="{{ asset('store/styles.css') }}?v={{ filemtime(public_path('store/styles.css')) }}" rel="stylesheet"><link href="{{ asset('store/theme.css') }}?v={{ filemtime(public_path('store/theme.css')) }}" rel="stylesheet"></head>
<body><a class="skip-link" href="#main">Bỏ qua điều hướng</a><div class="announcement">Giao hàng linh hoạt cho công trình <span aria-hidden="true">·</span> Tư vấn vật tư theo khối lượng <span aria-hidden="true">·</span> <a href="{{ route('estimate') }}">Dự toán ngay</a></div>
<header class="site-header" id="siteHeader">
    <div class="container-fluid container-wide topbar">
        <a class="small-link d-none d-md-inline" href="{{ route('catalog') }}">Tư vấn công trình</a>
        <a class="brand" href="{{ route('home') }}" aria-label="Sài Gòn CMC, về trang chủ"><span class="brand-mark" aria-hidden="true">S</span><span>SÀI GÒN CMC<small>VẬT LIỆU XÂY DỰNG</small></span></a>
        <div class="header-actions">
            @include('layouts.theme-toggle')
            <div class="dropdown account-dropdown">
                <button class="icon-link account-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mở menu tài khoản">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20c.4-4 3-6 7.5-6s7.1 2 7.5 6"/></svg>
                </button>
                <div class="dropdown-menu dropdown-menu-end account-dropdown-menu">
                    @auth
                        <div class="account-dropdown-head"><span class="eyebrow muted">TÀI KHOẢN</span><strong>{{ auth()->user()->name }}</strong></div>
                        @if(auth()->user()->role === 'customer')
                            <a class="dropdown-item" href="{{ route('my.quotes') }}">Báo giá của tôi</a>
                            <a class="dropdown-item" href="{{ route('my.contracts') }}">Hợp đồng</a>
                            <a class="dropdown-item" href="{{ route('my.orders') }}">Đơn hàng của tôi</a>
                            <a class="dropdown-item" href="{{ route('profile') }}">Thông tin cá nhân</a>
                        @else
                            <a class="dropdown-item" href="{{ route('staff.dashboard') }}">Bảng làm việc</a>
                        @endif
                    @else
                        <div class="account-dropdown-head"><span class="eyebrow muted">TÀI KHOẢN</span><strong>Chào mừng bạn</strong></div>
                        <a class="dropdown-item" href="{{ route('login') }}">Đăng nhập</a>
                        <a class="dropdown-item" href="{{ route('register') }}">Tạo tài khoản</a>
                    @endauth
                </div>
            </div>
            <a class="icon-link cart-link" href="{{ route('cart') }}" aria-label="Giỏ hàng"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16l-1.2 12H5.2L4 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg><span class="cart-count">{{ count(session('cart', [])) }}</span></a>
        </div>
    </div>
    <div class="container-fluid container-wide nav-row">
        <button class="menu-trigger" type="button" data-bs-toggle="offcanvas" data-bs-target="#siteMenu" aria-controls="siteMenu"><span class="menu-lines" aria-hidden="true"></span><span>Menu</span></button>
        <nav class="desktop-nav" aria-label="Điều hướng chính"><a href="{{ route('catalog') }}">Sản phẩm</a><a href="{{ route('estimate') }}">Dự toán vật liệu</a></nav>
        <form class="header-search" action="{{ route('catalog') }}" role="search"><label for="searchInput" class="visually-hidden">Tìm sản phẩm</label><input id="searchInput" name="q" type="search" value="{{ request('q') }}" placeholder="Tìm vật liệu cho công trình..."><button type="submit" aria-label="Tìm kiếm"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg></button></form>
    </div>
</header>
<div class="offcanvas offcanvas-start site-menu" tabindex="-1" id="siteMenu" aria-labelledby="menuTitle">
    <div class="offcanvas-header site-menu-head"><div><span class="eyebrow muted">Sài Gòn CMC</span><h2 class="offcanvas-title" id="menuTitle">Khám phá vật liệu.</h2></div><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Đóng menu"></button></div>
    <nav class="offcanvas-body site-menu-body" aria-label="Menu cửa hàng">
        <span class="site-menu-label">Cửa hàng</span>
        <a href="{{ route('home') }}">Trang chủ</a>
        <a href="{{ route('catalog') }}">Tất cả vật liệu</a>
        <a href="{{ route('estimate') }}">Dự toán công trình</a>
        <a href="{{ route('cart') }}">Giỏ vật liệu</a>
    </nav>
    <div class="site-menu-footer"><span class="eyebrow">Đồng hành cùng công trình</span><p>Chọn đúng vật liệu. Theo dõi từng bước, từ báo giá đến giao nhận.</p></div>
</div>
<main id="main" tabindex="-1">@if(session('success'))@include('layouts.notice', ['type' => 'success', 'messages' => [session('success')]])@endif @if($errors->any() && !request()->routeIs('login', 'register'))@include('layouts.notice', ['type' => 'error', 'messages' => $errors->all()])@endif @yield('content')</main>
@if(session('cart_added'))
@php($added = session('cart_added'))
<div class="cart-toast-wrap" aria-live="polite">
    <div class="toast show cart-toast" role="status" aria-atomic="true" data-bs-autohide="false">
        <div class="cart-toast-body">
            @if($added['image'])<span class="material-image cart-toast-image {{ $added['image'] }}" aria-hidden="true"></span>@else<span class="cart-toast-image cart-toast-check" aria-hidden="true">✓</span>@endif
            <div class="cart-toast-copy"><strong>{{ $added['replaced'] ? 'Đã cập nhật giỏ hàng' : 'Đã thêm vào giỏ hàng' }}</strong><span>Trong giỏ: {{ $added['quantity'] }} {{ $added['unit'] }} · {{ $added['name'] }}</span></div>
            <button class="btn-close" type="button" data-bs-dismiss="toast" aria-label="Đóng thông báo"></button>
        </div>
        <a class="cart-toast-action" href="{{ route('cart') }}">Xem giỏ báo giá</a>
    </div>
</div>
@endif
<aside class="chat-widget" aria-label="Trợ lý AI"><section class="chat-panel" id="chatPanel" aria-labelledby="chatTitle" hidden><div class="chat-header"><div class="chat-avatar" aria-hidden="true">S</div><div><strong id="chatTitle">Trợ lý Sài Gòn CMC</strong><small>Tư vấn vật liệu xây dựng</small></div><button class="chat-close" type="button" aria-label="Đóng trò chuyện" data-chat-close>×</button></div><div class="chat-messages" id="chatMessages" role="log" aria-live="polite"><p class="chat-message assistant">Xin chào! Bạn đang tìm vật liệu cho công trình nào? Mình có thể gợi ý theo danh mục hiện có.</p></div><p class="chat-note">Giá và khối lượng cần được nhân viên xác nhận trong báo giá.</p><form class="chat-form" id="chatForm" action="{{ route('assistant.ask') }}" method="post">@csrf<label class="visually-hidden" for="chatQuestion">Nhập câu hỏi</label><textarea id="chatQuestion" name="question" rows="2" minlength="3" maxlength="1000" placeholder="Nhập câu hỏi của bạn..." required></textarea><button type="submit" aria-label="Gửi câu hỏi"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6"/></svg></button></form></section><button class="chat-launcher" type="button" aria-label="Mở trợ lý AI" aria-controls="chatPanel" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4V5Z"/><path d="M8 9h8M8 12h5"/></svg></button></aside>
<footer class="site-footer"><div class="container-fluid container-wide"><div class="row gy-5"><div class="col-lg-6"><div class="footer-brand"><span class="brand-mark" aria-hidden="true">S</span><span>SÀI GÒN CMC<small>VẬT LIỆU XÂY DỰNG</small></span></div><p>Vật liệu rõ quy cách, quy trình báo giá và giao nhận minh bạch cho từng công trình.</p></div><div class="col-6 col-lg-3"><h3>Khám phá</h3><a href="{{ route('catalog') }}">Sản phẩm</a><a href="{{ route('cart') }}">Yêu cầu báo giá</a></div><div class="col-6 col-lg-3"><h3>Tài khoản</h3>@if(auth()->check() && auth()->user()->role !== 'customer')<a href="{{ route('staff.dashboard') }}">Bảng làm việc</a>@else <a href="{{ route('my.quotes') }}">Báo giá</a><a href="{{ route('my.contracts') }}">Hợp đồng</a><a href="{{ route('my.orders') }}">Đơn hàng</a><a href="{{ route('profile') }}">Hồ sơ và công nợ</a>@endif </div></div><div class="footer-bottom"><span>© {{ date('Y') }} Sài Gòn CMC.</span><span>Giá hiển thị tham khảo; báo giá được nhân viên xác nhận.</span></div></div></footer><script src="{{ asset('store/bootstrap.bundle.min.js') }}"></script><script src="{{ asset('store/components.js') }}?v={{ filemtime(public_path('store/components.js')) }}" defer></script><script src="{{ asset('store/ui.js') }}" defer></script><script src="{{ asset('store/theme.js') }}?v={{ filemtime(public_path('store/theme.js')) }}" defer></script></body></html>

