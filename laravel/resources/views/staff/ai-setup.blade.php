@extends('layouts.staff')
@section('title', 'Cấu hình AI | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro">
        <a href="{{ route('management.documents.index') }}">← Kho tri thức</a>
        <div class="eyebrow muted mt-3">QUẢN TRỊ / THIẾT LẬP TRỢ LÝ</div>
        <h1 class="page-title">Cấu hình AI</h1>
        <p class="page-subtitle">Kết nối Groq để trợ lý tạo câu trả lời từ các tài liệu đã được duyệt.</p>
    </div>
    <section class="order-card" style="max-width:760px">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h5 mb-0">Groq API</h2>
            <span class="badge {{ $configured ? 'bg-success' : 'bg-warning text-dark' }}">{{ $configured ? 'Đã có API key' : 'Chưa cấu hình API key' }}</span>
        </div>
        <p class="muted">Lấy khóa từ <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">Groq Console</a>. Khóa được mã hóa khi lưu và chỉ quản trị viên được thay đổi cấu hình.</p>
        <p id="ai-test-status" role="status" class="alert {{ session('ai_test_success') ? 'alert-success' : 'alert-warning' }}" @if(!session('ai_test')) hidden @endif>{{ session('ai_test') }}</p>
        <form id="groq-setup-form" method="post" action="{{ route('management.ai-setup.update') }}" autocomplete="off">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label w-100" for="groq-api-key">API key Groq</label>
                <input id="groq-api-key" class="form-control w-100" type="password" name="api_key" autocomplete="new-password" maxlength="512" placeholder="{{ $configured ? 'Để trống để giữ khóa hiện tại' : 'Nhập API key Groq' }}" aria-describedby="api-key-help">
                <p id="api-key-help" class="muted mt-2 mb-0">Khóa đã lưu không được hiển thị lại. Kiểm tra kết nối không lưu thay đổi; bấm Lưu cấu hình để áp dụng.</p>
            </div>
            <div class="mb-4">
                <label class="form-label w-100" for="groq-model">Model</label>
                <input id="groq-model" class="form-control w-100" name="model" value="{{ old('model', $model) }}" maxlength="120" required>
                <p class="muted mt-2">Ví dụ: openai/gpt-oss-20b. Dùng tên model mà tài khoản Groq của bạn được phép truy cập.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-dark" name="action" value="save">Lưu cấu hình</button>
                <button type="submit" class="btn btn-outline-dark" name="action" value="test">Kiểm tra kết nối</button>
                @if($configured)
                    <button type="submit" class="btn btn-outline-danger" name="action" value="clear" onclick="return confirm('Gỡ API key khỏi cấu hình trợ lý?')">Gỡ API key</button>
                @endif
            </div>
        </form>
    </section>
</div>
<script>
document.getElementById('groq-setup-form').addEventListener('submit', async (event) => {
    if (event.submitter?.value !== 'test') return;
    event.preventDefault();
    const button = event.submitter;
    const status = document.getElementById('ai-test-status');
    const data = new FormData(event.target);
    data.set('action', 'test');
    button.disabled = true;
    status.hidden = false;
    status.className = 'alert alert-info';
    status.textContent = 'Đang kiểm tra kết nối Groq…';
    try {
        const response = await fetch(event.target.action, { method: 'POST', headers: { Accept: 'application/json' }, body: data });
        const result = await response.json();
        status.className = response.ok && result.success ? 'alert alert-success' : 'alert alert-warning';
        status.textContent = result.errors?.api_key?.[0] || result.errors?.model?.[0] || result.message || 'Chưa kiểm tra được kết nối. Hãy thử lại.';
    } catch {
        status.className = 'alert alert-warning';
        status.textContent = 'Không kết nối được. Hãy thử lại.';
    } finally {
        button.disabled = false;
    }
});
</script>
@endsection
