@extends('layouts.staff')
@section('title', 'Kiểm tra bản sao lưu | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro">
        <a href="{{ route('management.backups.index') }}">← Danh sách sao lưu</a>
        <h1 class="page-title mt-3">Kiểm tra bản sao lưu</h1>
        <p class="page-subtitle">Tệp hợp lệ, đã xác thực và tương thích với cơ sở dữ liệu hiện tại.</p>
    </div>
    <section class="order-card mb-4">
        <h2 class="h5">Thông tin bản sao lưu</h2>
        <p>Mã: {{ $id }}</p>
        <p>Ngày tạo: {{ \Illuminate\Support\Carbon::parse($createdAt)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}</p>
        <p>Loại: {{ $reason === 'before-restore' ? 'Tự động trước khi khôi phục' : 'Sao lưu thủ công' }}</p>
        <p>{{ count($tables) }} bảng · {{ array_sum($tables) }} bản ghi · {{ $files }} tệp đính kèm · {{ number_format($size / 1024, 1, ',', '.') }} KB</p>
        <a class="btn btn-outline-dark" href="{{ route('management.backups.download', $id) }}">Tải bản sao lưu</a>
        <details class="mt-3"><summary>Số bản ghi theo từng bảng</summary>
            <div class="table-responsive mt-3"><table class="table"><thead><tr><th>Bảng</th><th>Bản ghi</th></tr></thead><tbody>
            @foreach($tables as $table => $count)<tr><td>{{ $table }}</td><td>{{ $count }}</td></tr>@endforeach
            </tbody></table></div>
        </details>
    </section>
    <section class="order-card" style="max-width:760px">
        <h2 class="h5">Khôi phục dữ liệu</h2>
        <p class="alert alert-warning">Thao tác thay thế dữ liệu và tệp đính kèm hiện tại bằng bản sao lưu này. Hệ thống tự tạo một bản dự phòng trước khi thực hiện. Sau khôi phục, mọi phiên đăng nhập bị xóa; bạn cần đăng nhập bằng tài khoản có trong bản sao lưu.</p>
        <p class="muted">Các tài liệu đã duyệt sẽ được lập chỉ mục RAG lại; tài liệu chưa duyệt trở về bản nháp. Chờ lập chỉ mục đang chạy hoàn tất trước khi khôi phục.</p>
        <form method="post" action="{{ route('management.backups.restore', $id) }}" id="restore-form">
            @csrf
            <label class="form-label w-100">Mật khẩu admin hiện tại<input type="password" name="password" autocomplete="current-password" class="form-control w-100" required></label>
            <label class="form-label w-100 mt-3">Nhập KHOI PHUC để xác nhận<input name="confirmation" autocomplete="off" class="form-control w-100" required pattern="KHOI PHUC" placeholder="KHOI PHUC"></label>
            <button class="btn btn-danger mt-3" type="submit">Xác nhận khôi phục</button>
            <p id="restore-status" class="muted mt-3" hidden role="status">Đang khôi phục. Vui lòng giữ trang và chờ hoàn tất…</p>
        </form>
    </section>
</div>
<script>
document.getElementById('restore-form').addEventListener('submit', event => {
    event.target.querySelector('button').disabled = true;
    document.getElementById('restore-status').hidden = false;
});
</script>
@endsection
