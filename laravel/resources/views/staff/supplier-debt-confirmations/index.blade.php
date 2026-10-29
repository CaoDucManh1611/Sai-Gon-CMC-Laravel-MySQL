<section class="mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><h1 class="h3">Chứng từ xác nhận công nợ nhà cung cấp</h1><p class="text-secondary mb-0">Đây là xác nhận được kế toán ghi nhận từ chứng từ liên hệ với nhà cung cấp; nhà cung cấp không đăng nhập hệ thống.</p></div><a class="btn btn-outline-dark" href="{{ route('staff.section', 'finance') }}">Quay lại tài chính</a></div>
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif

    <h2 class="h5">Các khoản phải trả</h2>
    @forelse($debts as $debt)
        <details class="order-card mb-3"><summary><strong>{{ $debt->code }}</strong> · {{ $debt->supplier_name }} · Còn {{ number_format(max(0, $debt->amount - $debt->paid), 0, ',', '.') }} ₫</summary>
            <form class="pt-3" method="post" enctype="multipart/form-data" action="{{ route('staff.supplier-debts.confirmations.store', $debt->id) }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Người liên hệ phía nhà cung cấp<input class="form-control" name="contact_name" maxlength="150" required></label></div>
                    <div class="col-md-3"><label class="form-label">Ngày nhà cung cấp xác nhận<input class="form-control" type="date" name="confirmed_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label></div>
                    <div class="col-md-5"><label class="form-label">Khoản thanh toán liên quan (nếu có)<select class="form-select" name="payment_id"><option value="">Không gắn với một khoản thanh toán cụ thể</option>@foreach($payments[$debt->id] ?? [] as $payment)<option value="{{ $payment->id }}">{{ $payment->code }} · {{ number_format($payment->amount, 0, ',', '.') }} ₫ · {{ $payment->paid_at }}</option>@endforeach</select></label></div>
                    <div class="col-12"><label class="form-label">Nội dung xác nhận<textarea class="form-control" name="statement" maxlength="4000" required placeholder="Ghi nội dung nhà cung cấp đã xác nhận và số dư họ đối chiếu"></textarea></label></div>
                    <div class="col-md-8"><label class="form-label">Tệp chứng từ (PDF, JPEG, PNG; tối đa 10 MB)<input class="form-control" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></label></div>
                    <div class="col-md-4 d-flex align-items-end"><button class="btn btn-dark">Lưu xác nhận kế toán đã ghi nhận</button></div>
                </div>
                <p class="small text-secondary mt-2 mb-0">Tác vụ chỉ lưu chứng từ, không tạo khoản chi, không ghi thanh toán và không thay đổi số dư.</p>
            </form>
        </details>
    @empty<div class="order-card text-secondary">Chưa có khoản phải trả nhà cung cấp.</div>@endforelse
    {{ $debts->links() }}

    <h2 class="h5 mt-5">Lịch sử chứng từ đã ghi nhận</h2>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Ngày</th><th>Nhà cung cấp / công nợ</th><th>Người liên hệ</th><th>Nội dung</th><th>Người ghi nhận</th><th>Tệp</th></tr></thead><tbody>
        @forelse($confirmations as $item)
            <tr><td>{{ $item->confirmed_on }}</td><td>{{ $item->supplier_name }}<small class="d-block">{{ $item->debt_code }}@if($item->payment_code) · {{ $item->payment_code }}@endif</small></td><td>{{ $item->contact_name }}</td><td>{{ $item->statement }}<small class="d-block text-secondary">xác nhận được kế toán ghi nhận</small></td><td>{{ $item->accountant_name }}</td><td><a href="{{ route('staff.supplier-debt-confirmations.attachment', $item->id) }}">{{ $item->file_name }}</a></td></tr>
        @empty<tr><td colspan="6" class="text-center text-secondary py-4">Chưa có chứng từ xác nhận nhà cung cấp.</td></tr>@endforelse
    </tbody></table></div>
    {{ $confirmations->links() }}
</section>
