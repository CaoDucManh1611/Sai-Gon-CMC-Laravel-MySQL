<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a36">
    <script>try{document.documentElement.dataset.theme=localStorage.getItem('cmc-theme')==='dark'?'dark':'light'}catch{document.documentElement.dataset.theme='light'}document.documentElement.dataset.bsTheme=document.documentElement.dataset.theme</script>
    <title>@yield('title', 'Không gian làm việc | Sài Gòn CMC')</title>
    <link rel="icon" href="{{ asset('store/favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('store/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('store/styles.css') }}" rel="stylesheet">
    <link href="{{ asset('store/staff.css') }}?v={{ filemtime(public_path('store/staff.css')) }}" rel="stylesheet">
    <link href="{{ asset('store/theme.css') }}?v={{ filemtime(public_path('store/theme.css')) }}" rel="stylesheet">
</head>
@php
    $staffRole = auth()->user()->role;
    $staffRoleName = ['sales' => 'Kinh doanh', 'warehouse' => 'Kho', 'accounting' => 'Kế toán', 'manager' => 'Quản lý', 'admin' => 'Quản trị viên', 'delivery' => 'Giao nhận'][$staffRole] ?? $staffRole;
    $staffSections = [
        'quotes' => ['Báo giá', 'BG', ['sales', 'manager', 'admin']],
        'contracts' => ['Hợp đồng', 'HĐ', ['sales', 'manager', 'admin']],
        'finance' => ['Tài chính & công nợ', 'TC', ['accounting', 'manager', 'admin']],
        'purchases' => ['Nhập hàng', 'NH', ['warehouse', 'manager', 'admin']],
        'orders' => ['Xuất kho & giao nhận', 'GN', ['warehouse', 'delivery', 'accounting', 'manager', 'admin']],
        'returns' => ['Đổi trả', 'ĐT', ['warehouse', 'accounting', 'manager', 'admin']],
        'inventory' => ['Tồn kho', 'TK', ['warehouse', 'manager', 'admin']],
    ];
@endphp
<body class="staff-app">
<a class="skip-link" href="#main">Bỏ qua điều hướng</a>
<div class="staff-shell">
    <aside class="staff-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="staffMenu" aria-labelledby="staffMenuTitle">
        <div class="staff-sidebar-top">
            <a class="staff-brand" href="{{ route('staff.dashboard') }}" id="staffMenuTitle" aria-label="Sài Gòn CMC, bảng làm việc">
                <span class="staff-brand-mark">S</span><span><strong>SÀI GÒN CMC</strong><small>KHÔNG GIAN LÀM VIỆC</small></span>
            </a>
            <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#staffMenu" aria-label="Đóng menu"></button>
        </div>
        <div class="staff-sidebar-art" aria-hidden="true">
            <span class="staff-art-caption">VẬT LIỆU <i></i> CÔNG TRÌNH</span>
        </div>
        <div class="staff-sidebar-scroll">
            <nav class="staff-nav" aria-label="Điều hướng nhân viên">
                <span class="staff-nav-label">TỔNG QUAN</span>
                <a href="{{ route('staff.dashboard') }}" @class(['is-active' => request()->routeIs('staff.dashboard')]) @if(request()->routeIs('staff.dashboard')) aria-current="page" @endif><span class="staff-nav-icon">01</span>Tổng quan</a>
                <span class="staff-nav-label">NGHIỆP VỤ</span>
                @foreach($staffSections as $key => [$label, $icon, $roles])
                    @if(in_array($staffRole, $roles, true))
                        <a href="{{ route('staff.section', $key) }}" @class(['is-active' => request()->routeIs('staff.section') && request()->route('section') === $key]) @if(request()->routeIs('staff.section') && request()->route('section') === $key) aria-current="page" @endif><span class="staff-nav-icon">{{ $icon }}</span>{{ $label }}</a>
                    @endif
                @endforeach
                <span class="staff-nav-label">HỆ THỐNG</span>
                @if(in_array($staffRole, ['sales', 'warehouse', 'manager', 'admin'], true))
                    <a href="{{ route('management.index') }}" @class(['is-active' => request()->routeIs('management.*')]) @if(request()->routeIs('management.*')) aria-current="page" @endif><span class="staff-nav-icon">DM</span>Danh mục & quản trị</a>
                @endif
                <a href="{{ route('staff.reports.index') }}" @class(['is-active' => request()->routeIs('staff.reports.*')]) @if(request()->routeIs('staff.reports.*')) aria-current="page" @endif><span class="staff-nav-icon">BC</span>Báo cáo thống kê</a>
            </nav>
        </div>
        <div class="staff-sidebar-bottom">
            <span class="staff-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
            <span class="staff-identity"><strong>{{ auth()->user()->name }}</strong><small>{{ $staffRoleName }}</small></span>
        </div>
    </aside>
    <div class="staff-page">
        <header class="staff-topbar">
            <button class="staff-menu-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#staffMenu" aria-controls="staffMenu" aria-label="Mở menu nghiệp vụ"><span></span><span></span><span></span></button>
            <div class="staff-topbar-context"><span class="staff-online-dot"></span><span>Hệ thống đang hoạt động</span></div>
            <div class="staff-topbar-actions">
                @include('layouts.theme-toggle')
                <span class="staff-role-pill">{{ $staffRoleName }}</span>
                <a href="{{ route('home') }}">Xem cửa hàng</a>
                <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Đăng xuất</button></form>
            </div>
        </header>
        <main id="main" class="staff-main" tabindex="-1">
            @if(session('success'))@include('layouts.notice', ['type' => 'success', 'messages' => [session('success')]])@endif
            @if($errors->any())@include('layouts.notice', ['type' => 'error', 'messages' => $errors->all()])@endif
            @yield('content')
        </main>
    </div>
</div>
<script src="{{ asset('store/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('store/components.js') }}" defer></script>
<script src="{{ asset('store/theme.js') }}?v={{ filemtime(public_path('store/theme.js')) }}" defer></script>
</body>
</html>
