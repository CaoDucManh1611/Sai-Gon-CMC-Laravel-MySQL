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
                <div class="auth-field">
                    <label for="email">Email đăng nhập</label>
                    <div class="auth-otp-row">
                        <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="ban@example.com" autocomplete="email" aria-describedby="emailHelp emailFeedback" @if($errors->has('email')) aria-invalid="true" @endif required>
                        <button type="button" class="auth-otp-button" id="sendRegistrationOtp">Gửi mã OTP</button>
                    </div>
                    <small id="emailHelp">Email này dùng để đăng nhập và nhận mã xác nhận đăng ký.</small>
                    <small id="emailFeedback" role="status" aria-live="polite" @class(['auth-otp-error' => $errors->has('email')])>{{ $errors->first('email') }}</small>
                </div>
                <div class="auth-field">
                    <label for="registrationOtp">Mã OTP xác nhận</label>
                    <input id="registrationOtp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="Nhập mã 6 số" aria-describedby="otpFeedback" @if($errors->has('otp')) aria-invalid="true" @endif>
                    <small id="otpFeedback" role="status" aria-live="polite" @class(['auth-otp-error' => $errors->has('otp')])>{{ $errors->first('otp') ?: 'Mã có hiệu lực trong 5 phút. Nhập mã rồi nhấn Tạo tài khoản.' }}</small>
                </div>
                <div class="auth-field"><label for="address">Địa chỉ liên hệ</label><textarea id="address" name="address" rows="2" placeholder="Địa chỉ nhận thông tin và liên hệ" required>{{ old('address') }}</textarea></div>
                <div class="auth-field"><label for="password">Mật khẩu</label><input id="password" name="password" type="password" minlength="8" placeholder="Ít nhất 8 ký tự" required autocomplete="new-password"></div>
                <div class="auth-field"><label for="password_confirmation">Xác nhận mật khẩu</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="8" placeholder="Nhập lại mật khẩu" required autocomplete="new-password"></div>
                <button class="btn-editorial dark auth-submit" type="submit">Tạo tài khoản</button>
            </form>
            <p class="auth-switch">Đã có tài khoản? <a href="{{ route('login') }}">Đăng nhập</a></p>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const email = document.getElementById('email');
    const otp = document.getElementById('registrationOtp');
    const emailFeedback = document.getElementById('emailFeedback');
    const otpFeedback = document.getElementById('otpFeedback');
    const send = document.getElementById('sendRegistrationOtp');
    const token = document.querySelector('input[name="_token"]').value;

    email.addEventListener('input', () => {
        emailFeedback.textContent = '';
        emailFeedback.classList.remove('auth-otp-error', 'auth-otp-success');
        email.removeAttribute('aria-invalid');
        otp.value = '';
        otpFeedback.textContent = 'Email đã thay đổi. Hãy gửi mã OTP đến email mới.';
        otpFeedback.classList.remove('auth-otp-error');
    });

    send.addEventListener('click', async () => {
        const address = email.value.trim();
        emailFeedback.classList.remove('auth-otp-error', 'auth-otp-success');
        if (!address || !email.checkValidity()) {
            emailFeedback.textContent = address ? 'Định dạng email không hợp lệ.' : 'Vui lòng nhập email đăng nhập.';
            emailFeedback.classList.add('auth-otp-error');
            email.setAttribute('aria-invalid', 'true');
            return;
        }

        emailFeedback.textContent = 'Định dạng email hợp lệ. Đang gửi mã OTP...';
        emailFeedback.classList.add('auth-otp-success');
        email.removeAttribute('aria-invalid');
        send.disabled = true;
        try {
            const response = await fetch(@json(route('register.otp.send')), {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token},
                credentials: 'same-origin',
                body: JSON.stringify({email: address}),
            });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(Object.values(result.errors || {})[0]?.[0] || result.message || 'Không thể xử lý mã OTP.');
            }
            if (email.value.trim() !== address) return;
            emailFeedback.textContent = 'Định dạng email hợp lệ. ' + result.message;
            otp.value = '';
            otpFeedback.textContent = 'Nhập mã OTP rồi nhấn Tạo tài khoản.';
            otpFeedback.classList.remove('auth-otp-error');
        } catch (error) {
            if (email.value.trim() !== address) return;
            emailFeedback.textContent = error.message || 'Không thể kết nối. Vui lòng thử lại.';
            emailFeedback.classList.remove('auth-otp-success');
            emailFeedback.classList.add('auth-otp-error');
            email.setAttribute('aria-invalid', 'true');
        } finally {
            send.disabled = false;
        }
    });
});
</script>
@endsection
