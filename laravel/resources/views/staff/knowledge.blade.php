@extends('layouts.staff')
@section('title', 'Kho tri thức | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro">
        <div class="eyebrow muted">TRỢ LÝ AI / DỮ LIỆU TƯ VẤN</div>
        <h1 class="page-title">Kho tri thức khách hàng</h1>
        <p class="page-subtitle">Cập nhật hướng dẫn mà trợ lý sử dụng để tư vấn khách hàng. Mỗi nguồn nhập trên một dòng.</p>
    </div>

    <div class="alert alert-info">Chatbot hiện sử dụng kho tài liệu RAG. <a href="{{ route('management.documents.index') }}">Tải tài liệu Markdown và quản lý chỉ mục tại đây</a>. Các mục dưới đây là nguồn tri thức cũ; chỉnh sửa ở đây không tự cập nhật chỉ mục RAG.</div>
    <form method="post" action="{{ route('management.knowledge.update') }}">
        @csrf
        @method('PUT')
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <p class="muted mb-0">{{ count($chunks) }} mục tri thức</p>
            <button class="btn btn-outline-dark" type="button" data-add-chunk>Thêm mục tri thức</button>
        </div>

        <div id="knowledge-chunks">
            @foreach($chunks as $index => $chunk)
                <article class="order-card" data-knowledge-chunk data-knowledge-index="{{ $index }}">
                    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                        <h2 class="h5 mb-0">Mục tri thức</h2>
                        <button class="btn btn-outline-danger" type="button" data-remove-chunk>Xóa mục</button>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="knowledge-{{ $index }}-id">ID mục
                                <input class="form-control" id="knowledge-{{ $index }}-id" name="chunks[{{ $index }}][id]" value="{{ $chunk['id'] ?? '' }}" maxlength="120" required>
                            </label>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="knowledge-{{ $index }}-topic">Chủ đề
                                <input class="form-control" id="knowledge-{{ $index }}-topic" name="chunks[{{ $index }}][topic]" value="{{ $chunk['topic'] ?? '' }}" maxlength="100" required>
                            </label>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="knowledge-{{ $index }}-title">Tiêu đề
                                <input class="form-control" id="knowledge-{{ $index }}-title" name="chunks[{{ $index }}][title]" value="{{ $chunk['title'] ?? '' }}" maxlength="200" required>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="knowledge-{{ $index }}-content">Nội dung
                                <textarea class="form-control" id="knowledge-{{ $index }}-content" name="chunks[{{ $index }}][content]" rows="5" maxlength="10000" required>{{ $chunk['content'] ?? '' }}</textarea>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="knowledge-{{ $index }}-sources">Nguồn tham khảo
                                <textarea class="form-control" id="knowledge-{{ $index }}-sources" name="chunks[{{ $index }}][sources]" rows="3" required>{{ $chunk['sources'] ?? '' }}</textarea>
                            </label>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <template id="knowledge-chunk-template">
            <article class="order-card" data-knowledge-chunk data-knowledge-index="__INDEX__">
                <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                    <h2 class="h5 mb-0">Mục tri thức mới</h2>
                    <button class="btn btn-outline-danger" type="button" data-remove-chunk>Xóa mục</button>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="knowledge-__INDEX__-id">ID mục
                            <input class="form-control" id="knowledge-__INDEX__-id" name="chunks[__INDEX__][id]" maxlength="120" required>
                        </label>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="knowledge-__INDEX__-topic">Chủ đề
                            <input class="form-control" id="knowledge-__INDEX__-topic" name="chunks[__INDEX__][topic]" maxlength="100" required>
                        </label>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="knowledge-__INDEX__-title">Tiêu đề
                            <input class="form-control" id="knowledge-__INDEX__-title" name="chunks[__INDEX__][title]" maxlength="200" required>
                        </label>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="knowledge-__INDEX__-content">Nội dung
                            <textarea class="form-control" id="knowledge-__INDEX__-content" name="chunks[__INDEX__][content]" rows="5" maxlength="10000" required></textarea>
                        </label>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="knowledge-__INDEX__-sources">Nguồn tham khảo
                            <textarea class="form-control" id="knowledge-__INDEX__-sources" name="chunks[__INDEX__][sources]" rows="3" required></textarea>
                        </label>
                    </div>
                </div>
            </article>
        </template>

        <button class="btn btn-dark" type="submit">Lưu kho tri thức</button>
    </form>
</div>
<script>
    (() => {
        const chunks = document.getElementById('knowledge-chunks');
        const template = document.getElementById('knowledge-chunk-template');
        let nextIndex = Array.from(
            chunks.querySelectorAll('[data-knowledge-index]'),
            (chunk) => Number(chunk.dataset.knowledgeIndex),
        ).reduce((highest, index) => Math.max(highest, index), -1) + 1;

        document.querySelector('[data-add-chunk]').addEventListener('click', () => {
            const markup = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
            chunks.insertAdjacentHTML('beforeend', markup);
            chunks.lastElementChild.querySelector('input').focus();
        });

        chunks.addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-chunk]');
            if (button) {
                button.closest('[data-knowledge-chunk]').remove();
            }
        });
    })();
</script>
@endsection
