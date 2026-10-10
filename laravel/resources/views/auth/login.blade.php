@extends('layouts.store')
@section('title','Đăng nhập | Sài Gòn CMC')
@section('content')
<div class="auth-page">
    <div class="auth-visual auth-visual-login">
        <div class="auth-visual-copy"><span class="eyebrow">Sài Gòn CMC</span><h2>Mọi công trình đều bắt đầu từ lựa chọn đúng.</h2><p>Vật liệu rõ quy cách. Báo giá và tiến độ luôn trong tầm tay.</p></div>
    </div>
    <div class="auth-content">
        <div class="auth-form-wrap">
            <span class="eyebrow muted">Tài khoản khách hàng</span>
            <h1>Chào mừng<br>trở lại.</h1>
            <p class="auth-lead">Theo dõi báo giá, hợp đồng và đơn hàng của công trình.</p>
            @if($errors->any())<div class="auth-error" role="alert">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('login.submit') }}">
                @csrf
                <div class="auth-field"><label for="login">Số điện thoại đã đăng ký</label><input id="login" name="login" type="text" value="{{ old('login') }}" placeholder="Ví dụ: 0901234567" required autocomplete="username" aria-describedby="loginHelp" @if($errors->has('login')) aria-invalid="true" @endif><small id="loginHelp">Nhân viên có thể dùng tên đăng nhập được cấp. Họ tên không dùng để đăng nhập.</small></div>
                <div class="auth-field"><label for="password">Mật khẩu</label><input id="password" type="password" name="password" placeholder="Nhập mật khẩu" required autocomplete="current-password"></div>
                <label class="auth-remember"><input type="checkbox" name="remember" value="1"> Ghi nhớ đăng nhập</label>
                <button class="btn-editorial dark auth-submit" type="submit">Đăng nhập</button>
            </form>
            <p class="auth-switch">Chưa có tài khoản? <a href="{{ route('register') }}">Tạo tài khoản</a></p>
        </div>
    </div>
</div>
@endsection
