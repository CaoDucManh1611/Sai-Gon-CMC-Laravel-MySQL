@extends('layouts.store')
@section('title','Đăng ký | Sài Gòn CMC')
@section('content')
<div class="auth-page auth-page-register">
    <div class="auth-visual auth-visual-register">
        <div class="auth-visual-copy"><span class="eyebrow">Bắt đầu cùng Sài Gòn CMC</span><h2>Chọn vật liệu. Chúng tôi đồng hành cùng công trình.</h2><p>Từ nhu cầu đầu tiên đến khi nhận đủ hàng.</p></div>
    </div>
    <div class="auth-content">
        <div class="auth-form-wrap">
            <span class="eyebrow muted">Tài khoản khách hàng</span>
            <h1>Bắt đầu từ<br>đây.</h1>
            <p class="auth-lead">Tạo tài khoản để lưu giỏ vật liệu và theo dõi báo giá.</p>
            @if($errors->any())<div class="auth-error" role="alert"><strong>Vui lòng kiểm tra:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <form method="post" action="{{ route('register.submit') }}">
                @csrf
                <div class="auth-field"><label for="name">Họ và tên</label><input id="name" name="name" value="{{ old('name') }}" placeholder="Tên người liên hệ" required autocomplete="name"></div>
                <div class="auth-field"><label for="phone">Số điện thoại đăng nhập</label><input id="phone" name="phone" type="tel" value="{{ old('phone') }}" placeholder="Ví dụ: 0901234567" required autocomplete="tel" inputmode="tel" aria-describedby="phoneHelp"><small id="phoneHelp">Sau này dùng số này để đăng nhập, không dùng họ tên.</small></div>
                <div class="auth-field"><label for="address">Địa chỉ liên hệ</label><textarea id="address" name="address" rows="2" placeholder="Địa chỉ nhận thông tin và liên hệ" required>{{ old('address') }}</textarea></div>
                <div class="auth-field"><label for="password">Mật khẩu</label><input id="password" name="password" type="password" minlength="8" placeholder="Ít nhất 8 ký tự" required autocomplete="new-password"></div>
                <div class="auth-field"><label for="password_confirmation">Xác nhận mật khẩu</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="8" placeholder="Nhập lại mật khẩu" required autocomplete="new-password"></div>
                <button class="btn-editorial dark auth-submit" type="submit">Tạo tài khoản</button>
            </form>
            <p class="auth-switch">Đã có tài khoản? <a href="{{ route('login') }}">Đăng nhập</a></p>
        </div>
    </div>
</div>
@endsection
