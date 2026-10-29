<section class="mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><h1 class="h3">Đối chiếu công nợ khách hàng</h1><p class="text-secondary mb-0">Mỗi lần gửi là một snapshot có phiên bản riêng. Số dư được tính từ sổ công nợ và giao dịch đã ghi nhận.</p></div>
        <a class="btn btn-outline-dark" href="{{ route('staff.section', 'finance') }}">Quay lại tài chính</a>
    </div>

    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif

    <form method="post" action="{{ route('staff.debt-reconciliations.create') }}" class="order-card mb-4">
        @csrf
        <h2 class="h5">Lập snapshot mới</h2>
        <div class="row g-3 align-items-end">
            <div class="col-md-4"><label for="reconcile-customer" class="form-label">Khách hàng</label><select id="reconcile-customer" class="form-select" name="customer_id" required><option value="">Chọn khách hàng</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->name }}@if($customer->username) · {{ $customer->username }}@endif</option>@endforeach</select></div>
            <div class="col-md-3"><label for="reconcile-start" class="form-label">Từ ngày</label><input id="reconcile-start" class="form-control" type="date" name="period_start" value="{{ old('period_start', $defaultPeriodStart) }}" required></div>
            <div class="col-md-3"><label for="reconcile-end" class="form-label">Đến ngày</label><input id="reconcile-end" class="form-control" type="date" name="as_of_date" value="{{ old('as_of_date', now()->toDateString()) }}" required></div>
            <div class="col-md-2"><button class="btn btn-dark w-100">Tạo bản nháp</button></div>
        </div>
        <p class="small text-secondary mt-3 mb-0">Nếu chi tiết đơn hàng đã được sửa trong kỳ mà hệ thống không lưu lịch sử giá trị cũ, hãy chọn kỳ bắt đầu sau lần sửa để tránh lập số liệu suy đoán.</p>
    </form>

    <h2 class="h5">Các phiên bản đã lập</h2>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Khách</th><th>Kỳ đối chiếu</th><th>Phiên bản</th><th>Số dư cuối kỳ</th><th>Trạng thái</th><th></th></tr></thead><tbody>
        @forelse($reconciliations as $row)
            <tr><td>{{ $row->customer_name }}</td><td>{{ $row->period_start }} – {{ $row->as_of_date }}</td><td>v{{ $row->version_number }}</td><td>{{ number_format($row->closing_balance, 0, ',', '.') }} ₫</td><td>{{ \App\Support\UiFormat::status($row->status) }}</td><td><a href="{{ route('staff.debt-reconciliations.show', $row->id) }}">Mở hồ sơ</a></td></tr>
        @empty<tr><td colspan="6" class="text-center text-secondary py-4">Chưa có bản đối chiếu.</td></tr>@endforelse
    </tbody></table></div>
    {{ $reconciliations->links() }}
</section>
