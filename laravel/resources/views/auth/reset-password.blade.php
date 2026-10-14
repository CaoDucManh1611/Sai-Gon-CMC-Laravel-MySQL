@extends('layouts.store')
@section('title', 'Đặt lại mật khẩu | Sài Gòn CMC')
@section('content')
<div class="auth-page">
    <div class="auth-visual auth-visual-login">
        <div class="auth-visual-copy"><span class="eyebrow">Sài Gòn CMC</span><h2>Bảo vệ tài khoản của bạn.</h2><p>Chọn mật khẩu mới để tiếp tục theo dõi hoạt động công trình.</p></div>
    </div>
    <div class="auth-content">
        <div class="auth-form-wrap">
            <span class="eyebrow muted">Khôi phục tài khoản</span>
            <h1>Đặt mật<br>khẩu mới.</h1>
            <p class="auth-lead">Liên kết chỉ sử dụng được trong thời hạn có hiệu lực.</p>
            @if($errors->any())<div class="auth-error" role="alert">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="auth-field"><label for="email">Email nhận liên kết</label><input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email" @if($errors->has('email')) aria-invalid="true" @endif></div>
                <div class="auth-field"><label for="password">Mật khẩu mới</label><input id="password" name="password" type="password" minlength="8" placeholder="Ít nhất 8 ký tự" required autocomplete="new-password" @if($errors->has('password')) aria-invalid="true" @endif></div>
                <div class="auth-field"><label for="password_confirmation">Xác nhận mật khẩu</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="8" placeholder="Nhập lại mật khẩu mới" required autocomplete="new-password"></div>
                <button class="btn-editorial dark auth-submit" type="submit">Cập nhật mật khẩu</button>
            </form>
            <p class="auth-switch"><a href="{{ route('password.request') }}">Yêu cầu liên kết mới</a></p>
        </div>
    </div>
</div>
@endsection
