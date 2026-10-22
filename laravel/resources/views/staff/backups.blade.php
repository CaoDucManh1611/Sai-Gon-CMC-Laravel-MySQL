@extends('layouts.staff')
@section('title', 'Sao lưu & khôi phục | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro">
        <div class="eyebrow muted">QUẢN TRỊ / DỮ LIỆU HỆ THỐNG</div>
        <h1 class="page-title">Sao lưu & khôi phục</h1>
        <p class="page-subtitle">Bảo vệ dữ liệu nghiệp vụ, tài liệu đính kèm và cấu hình trợ lý.</p>
    </div>
    <div class="row g-4 mb-4">
        <section class="col-lg-6"><div class="order-card h-100">
            <h2 class="h5">Tạo bản sao lưu</h2>
            <p>Bao gồm cơ sở dữ liệu, tài liệu Markdown, tệp hợp đồng, tệp tải lên và cấu hình AI. Toàn bộ tệp sao lưu được mã hóa.</p>
            <p class="muted">Không bao gồm mã nguồn, mô hình Ollama, chỉ mục vector và phiên đăng nhập. Giới hạn dữ liệu 128 MB.</p>
            <form method="post" action="{{ route('management.backups.store') }}" data-backup-form>
                @csrf
                <button type="submit" class="btn btn-dark">Tạo bản sao lưu ngay</button>
            </form>
        </div></section>
        <section class="col-lg-6"><div class="order-card h-100">
            <h2 class="h5">Nhập bản sao lưu</h2>
            <p>Chọn tệp .cmcbackup đã tải về. Hệ thống kiểm tra tệp trước và hiển thị nội dung tổng quan để bạn duyệt.</p>
            <form method="post" action="{{ route('management.backups.upload') }}" enctype="multipart/form-data" data-backup-form>
                @csrf
                <label class="form-label w-100">Tệp sao lưu<input type="file" name="backup" accept=".cmcbackup" class="form-control w-100" required></label>
                <button type="submit" class="btn btn-outline-dark mt-2">Tải lên và kiểm tra</button>
            </form>
        </div></section>
    </div>
    <p class="alert alert-info">Giữ an toàn APP_KEY của hệ thống. Chỉ bản sao lưu có cùng APP_KEY và cấu trúc cơ sở dữ liệu tương thích mới khôi phục được. Chỉ mục RAG được tạo lại từ tài liệu sau khôi phục.</p>
    <section class="order-card">
        <h2 class="h5 mb-3">{{ count($backups) }} bản sao lưu</h2>
        <div class="table-responsive"><table class="table align-middle">
            <thead><tr><th>Thời gian tạo / nhập</th><th>Mã bản sao lưu</th><th>Dung lượng</th><th>Thao tác</th></tr></thead>
            <tbody>
            @forelse($backups as $backup)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($backup['time'])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}</td>
                    <td><small>{{ $backup['id'] }}</small></td>
                    <td>{{ number_format($backup['size'] / 1024, 1, ',', '.') }} KB</td>
                    <td><div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-sm btn-outline-dark" href="{{ route('management.backups.download', $backup['id']) }}">Tải xuống</a>
                        <a class="btn btn-sm btn-dark" href="{{ route('management.backups.show', $backup['id']) }}">Xem & khôi phục</a>
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Chưa có bản sao lưu. Tạo bản đầu tiên để bảo vệ dữ liệu hiện tại.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</div>
<script>
document.querySelectorAll('[data-backup-form]').forEach(form => form.addEventListener('submit', () => {
    form.querySelectorAll('button').forEach(button => { button.disabled = true; button.textContent = 'Đang xử lý…'; });
}));
</script>
@endsection
