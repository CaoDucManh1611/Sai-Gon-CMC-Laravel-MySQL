@extends('layouts.store')
@section('title', 'Quên mật khẩu | Sài Gòn CMC')
@section('content')
<div class="auth-page">
    <div class="auth-visual auth-visual-login">
        <div class="auth-visual-copy"><span class="eyebrow">Sài Gòn CMC</span><h2>Tiếp tục theo dõi công trình của bạn.</h2><p>Khôi phục quyền truy cập để xem báo giá, hợp đồng và đơn hàng.</p></div>
    </div>
    <div class="auth-content">
        <div class="auth-form-wrap">
            <span class="eyebrow muted">Khôi phục tài khoản</span>
            <h1>Quên mật<br>khẩu?</h1>
            <p class="auth-lead">Nhập email đăng nhập (hoặc số điện thoại/tên đăng nhập cũ). Liên kết đặt lại mật khẩu sẽ được gửi tới email đã lưu.</p>
            @if(session('status'))<div class="auth-status" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="auth-error" role="alert">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('password.email') }}">
                @csrf
                <div class="auth-field"><label for="login">Email hoặc tên đăng nhập</label><input id="login" name="login" type="text" value="{{ old('login') }}" placeholder="ban@example.com" required autocomplete="username" @if($errors->has('login')) aria-invalid="true" @endif></div>
                <button class="btn-editorial dark auth-submit" type="submit">Gửi liên kết khôi phục</button>
            </form>
            <p class="auth-switch"><a href="{{ route('login') }}">Quay lại đăng nhập</a></p>
        </div>
    </div>
</div>
@endsection
