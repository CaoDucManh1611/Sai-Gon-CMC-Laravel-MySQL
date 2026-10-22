@extends('layouts.staff')
@section('title', 'Tài liệu tri thức | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro">
        <div class="eyebrow muted">TRỢ LÝ AI / KHO TÀI LIỆU</div>
        <h1 class="page-title">Kho tri thức khách hàng</h1>
        <p class="page-subtitle">Tải Markdown, kiểm tra các phần nội dung và duyệt trước khi trợ lý sử dụng.</p>
        <a href="{{ route('management.ai-setup') }}" class="btn btn-outline-dark mt-2">Cấu hình AI / API key</a>
    </div>
    <div class="d-flex flex-wrap gap-3 mb-4" role="status">
        <span class="badge {{ $health['embedding'] ? 'bg-success' : 'bg-warning text-dark' }}">Embedding: {{ $health['embedding'] ? 'sẵn sàng' : 'chưa sẵn sàng' }}</span>
        <span class="badge {{ $health['vector'] ? 'bg-success' : 'bg-warning text-dark' }}">Tìm kiếm vector: {{ $health['vector'] ? 'sẵn sàng' : 'chưa kết nối' }}</span>
    </div>
    @if(session('error'))<p class="alert alert-danger">{{ session('error') }}</p>@endif
    <section class="order-card mb-4">
        <h2 class="h5">Thêm tài liệu Markdown</h2>
        <p class="muted">Dùng #, ##, ### để phân chia đề mục. Hệ thống giữ các đoạn cùng ý, bảng và khối mã; tài liệu tối đa 1 MB, 200 phần.</p>
        <form method="post" action="{{ route('management.documents.store') }}" enctype="multipart/form-data" class="row g-3">
            @csrf
            <div class="col-md-5"><label class="form-label w-100">Tên tài liệu<input name="title" value="{{ old('title') }}" class="form-control w-100" maxlength="200" required></label></div>
            <div class="col-md-5"><label class="form-label w-100">Tệp Markdown (.md)<input type="file" name="document" class="form-control w-100" accept=".md,.markdown,text/markdown,text/plain" required></label></div>
            <div class="col-md-2 d-flex align-items-end pb-2"><button class="btn btn-dark" type="submit">Tải và xem trước</button></div>
        </form>
    </section>
    <section class="mb-4">
        <h2 class="h5">{{ $documents->total() }} tài liệu</h2>
        @php($statuses = ['draft' => 'Chờ duyệt', 'queued' => 'Chờ xử lý', 'indexing' => 'Đang lập chỉ mục', 'ready' => 'Sẵn sàng', 'error' => 'Xử lý lỗi'])
        @forelse($documents as $document)
            <article class="order-card mb-3">
                <div class="d-flex justify-content-between flex-wrap gap-3">
                    <div>
                        <h3 class="h5"><a href="{{ route('management.documents.show', $document->id) }}">{{ $document->title }}</a></h3>
                        <p class="muted mb-1">{{ $document->filename }} · {{ count(json_decode($document->chunks, true)) }} phần · {{ $statuses[$document->status] ?? $document->status }}</p>
                        <small>{{ $document->active ? 'Đang bật' : 'Đã tắt' }} @if($document->indexed_at) · Cập nhật chỉ mục: {{ $document->indexed_at }} @endif</small>
                        @if($document->error)<p class="text-danger mt-2 mb-0">{{ $document->error }}</p>@endif
                    </div>
                    <div class="d-flex gap-2 align-items-start">
                        <a class="btn btn-outline-dark" href="{{ route('management.documents.show', $document->id) }}">Xem / chỉnh sửa</a>
                        <form method="post" action="{{ route('management.documents.toggle', $document->id) }}">@csrf @method('PATCH')<button class="btn btn-outline-dark">{{ $document->active ? 'Tắt sử dụng' : 'Bật sử dụng' }}</button></form>
                    </div>
                </div>
            </article>
        @empty
            <p class="order-card muted">Chưa có tài liệu. Tải tệp .md đầu tiên để tạo kho tri thức.</p>
        @endforelse
        {{ $documents->links() }}
    </section>
    <section class="order-card">
        <h2 class="h5">Thử truy xuất tài liệu</h2>
        <p class="muted">Xem các phần phù hợp với câu hỏi trước khi nhờ trợ lý trả lời.</p>
        <form method="post" action="{{ route('management.documents.search') }}" class="d-flex gap-2 flex-wrap">
            @csrf
            <label class="flex-grow-1">Câu hỏi<input class="form-control w-100" name="question" value="{{ $question ?? '' }}" minlength="3" maxlength="1000" required></label>
            <button class="btn btn-dark align-self-end" type="submit">Tìm nội dung liên quan</button>
        </form>
        @isset($results)
            @forelse($results as $result)
                <article class="border-top mt-3 pt-3">
                    <h3 class="h6"><a href="{{ $result['url'] }}" target="_blank" rel="noopener">{{ $result['title'] }} — {{ $result['section'] }}</a></h3>
                    <small class="muted">Độ tương đồng: {{ number_format($result['score'], 3) }}</small>
                    <p style="white-space:pre-wrap" class="mt-2">{{ $result['content'] }}</p>
                </article>
            @empty
                <p class="muted mt-3">Chưa tìm thấy nội dung đủ liên quan trong các tài liệu đang bật và đã lập chỉ mục.</p>
            @endforelse
        @endisset
    </section>
    <p class="mt-4"><a href="{{ route('management.knowledge') }}">Mở trình biên tập tri thức cũ</a></p>
</div>
@endsection
