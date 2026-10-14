@extends('layouts.store')
@section('title', 'Tài khoản | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide account-page">
    <header class="account-hero">
        <div>
            <h1>Thông tin của bạn.</h1>
            <p>Quản lý thông tin liên hệ, công nợ và các khoản hoàn từ công trình của bạn.</p>
        </div>
        <div class="account-identity">
            <span class="account-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email ?? auth()->user()->phone }}</small></div>
        </div>
    </header>

    <div class="account-grid">
        <section class="account-card account-form-card">
            <span class="eyebrow muted">HỒ SƠ</span>
            <h2>Thông tin cá nhân</h2>
            <p class="account-card-intro">Thông tin này giúp chúng tôi liên hệ khi xác nhận báo giá và giao hàng. Email có thể dùng để khôi phục mật khẩu.</p>
            <form method="post" action="{{ route('profile.update') }}">
                @csrf
                @method('put')
                <label class="form-label" for="name">Họ và tên</label>
                <input class="form-control" id="name" name="name" value="{{ old('name', auth()->user()->name) }}" required oninvalid="this.setCustomValidity('Vui lòng nhập họ và tên.')" oninput="this.setCustomValidity('')">
                <label class="form-label" for="phone">Số điện thoại liên hệ (không bắt buộc)</label>
                <input class="form-control" id="phone" name="phone" value="{{ old('phone', auth()->user()->phone) }}" oninput="this.setCustomValidity('')">
                <label class="form-label" for="email">Email đăng nhập / khôi phục</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email', auth()->user()->email) }}" autocomplete="email">
                <label class="form-label" for="address">Địa chỉ</label>
                <textarea class="form-control" id="address" name="address" required oninvalid="this.setCustomValidity('Vui lòng nhập địa chỉ liên hệ.')" oninput="this.setCustomValidity('')">{{ old('address', auth()->user()->address) }}</textarea>
                <button class="btn-editorial dark" type="submit">Lưu thông tin</button>
            </form>
            <form method="post" action="{{ route('logout') }}" class="account-logout-form">
                @csrf
                <button type="submit" class="account-logout">Đăng xuất</button>
            </form>
        </section>

        <section class="account-card">
            <span class="eyebrow muted">THEO DÕI THANH TOÁN</span>
            <h2>Công nợ của tôi</h2>
            @if($debts->isEmpty())
                <div class="account-empty"><span aria-hidden="true">✓</span><strong>Chưa phát sinh công nợ.</strong><p>Các khoản cần thanh toán sẽ hiển thị tại đây.</p></div>
            @else
                <div class="customer-debt-filter" role="search" aria-label="Lọc công nợ" data-debt-filter>
                    <label>Tìm công nợ<input type="search" class="form-control" placeholder="Nhập mã hoặc nội dung" aria-label="Tìm công nợ"></label>
                    <label>Trạng thái<select class="form-select" aria-label="Trạng thái công nợ"><option value="">Tất cả</option><option value="open">Còn phải trả</option><option value="settled">Đã thanh toán</option></select></label>
                    <label>Sắp xếp<select class="form-select" aria-label="Sắp xếp công nợ"><option value="new">Mới nhất trước</option><option value="old">Cũ nhất trước</option></select></label>
                    <button class="customer-debt-clear" type="button">Xóa lọc</button>
                    <output class="customer-debt-count" aria-live="polite"></output>
                </div>
                <div class="account-table-scroll" role="region" aria-label="Công nợ của tôi" tabindex="0">
                    <table class="table">
                        <thead><tr><th>Mã</th><th>Giá trị</th><th>Đã thanh toán</th><th>Còn lại</th><th>Hẹn quyết toán</th></tr></thead>
                        <tbody>
                            @foreach($debts as $debt)
                                <tr><td>{{ $debt->code }}</td><td>{{ number_format($debt->amount, 0, ',', '.') }} ₫</td><td>{{ number_format($debt->paid, 0, ',', '.') }} ₫</td><td>{{ number_format(max(0, $debt->amount - $debt->paid), 0, ',', '.') }} ₫</td><td>{{ $debt->due_on ?: 'Chưa hẹn' }} @if($debt->settlement_note)<small class="d-block">{{ $debt->settlement_note }}</small>@endif</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="customer-debt-empty" hidden>Không có khoản công nợ phù hợp.</p>
            @endif
        </section>

        <section class="account-card account-credit-card">
            <span class="eyebrow muted">ĐỐI SOÁT</span>
            <h2>Tiền được hoàn hoặc cấn trừ</h2>
            @if($credits->isEmpty())
                <div class="account-empty compact"><span aria-hidden="true">↗</span><strong>Chưa có khoản hoàn hoặc cấn trừ.</strong><p>Khi phát sinh trả hàng, thông tin xử lý sẽ xuất hiện tại đây.</p></div>
            @else
                <div class="account-table-scroll" role="region" aria-label="Tiền được hoàn hoặc cấn trừ" tabindex="0">
                    <table class="table">
                        <thead><tr><th>Phiếu trả</th><th>Giá trị</th><th>Đã xử lý</th><th>Còn lại</th></tr></thead>
                        <tbody>
                            @foreach($credits as $credit)
                                @php($used = (string) ($creditUsed[$credit->id] ?? 0))
                                <tr><td>#{{ $credit->return_id }}</td><td>{{ number_format($credit->amount, 0, ',', '.') }} ₫</td><td>{{ number_format($used, 0, ',', '.') }} ₫</td><td>{{ number_format(bcsub((string) $credit->amount, $used, 2), 0, ',', '.') }} ₫</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
@endsection
