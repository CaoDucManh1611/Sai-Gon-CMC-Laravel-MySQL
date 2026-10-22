@extends('layouts.staff')
@section('title', 'Duyệt tài liệu | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide pb-5">
    <div class="page-intro">
        <a href="{{ route('management.documents.index') }}">← Kho tài liệu</a>
        <h1 class="page-title mt-3">{{ $document->title }}</h1>
        <p class="page-subtitle">{{ $document->filename }} · Các phần được chia theo đề mục và ngữ nghĩa. Bạn có thể chỉnh sửa, gộp hoặc tách trước khi duyệt.</p>
    </div>
    @php($busy = in_array($document->status, ['queued', 'indexing'], true))
    @if($busy)
        <p class="alert alert-info" role="status">Đang chờ xử lý hoặc tạo chỉ mục. Tải lại trang để cập nhật trạng thái; tài liệu chỉ được dùng khi xử lý thành công.</p>
    @elseif($document->status === 'ready')
        <p class="alert alert-success" role="status">Đã lập chỉ mục. {{ $document->active ? 'Trợ lý có thể truy xuất tài liệu này.' : 'Tài liệu đang tắt sử dụng.' }} Lưu thay đổi sẽ chuyển về bản nháp và cần duyệt lại.</p>
    @endif
    @if($document->error)<p class="alert alert-danger">{{ $document->error }}</p>@endif
    <form method="post" action="{{ route('management.documents.update', $document->id) }}" id="document-editor">
        @csrf @method('PUT')
        <fieldset @disabled($busy)>
            <label class="form-label w-100 mb-4">Tên tài liệu<input class="form-control w-100" name="title" value="{{ old('title', $document->title) }}" maxlength="200" required></label>
            <div id="document-chunks">
                @foreach(old('chunks', $chunks) as $index => $chunk)
                    <article class="order-card mb-3" data-chunk>
                        <label class="form-label w-100">Đề mục<input class="form-control w-100" name="chunks[{{ $index }}][title]" value="{{ $chunk['title'] }}" maxlength="500" required></label>
                        <label class="form-label w-100">Nội dung<textarea class="form-control w-100" name="chunks[{{ $index }}][content]" rows="8" maxlength="12000" required>{{ $chunk['content'] }}</textarea></label>
                        <input type="hidden" name="chunks[{{ $index }}][line]" value="{{ $chunk['line'] }}">
                        <div class="d-flex gap-2 flex-wrap mt-2">
                            <button type="button" class="btn btn-outline-dark" data-split>Tách tại con trỏ trong nội dung</button>
                            <button type="button" class="btn btn-outline-dark" data-merge>Gộp với phần trước</button>
                            <button type="button" class="btn btn-outline-danger" data-remove>Xóa phần</button>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="d-flex gap-3 flex-wrap my-4">
                <button class="btn btn-outline-dark" type="button" id="add-document-chunk">Thêm phần</button>
                <button class="btn btn-outline-dark" type="submit" name="action" value="save">Lưu bản nháp</button>
                <button class="btn btn-dark" type="submit" name="action" value="index">Duyệt và lập chỉ mục</button>
            </div>
        </fieldset>
    </form>
    <details class="order-card mb-4"><summary>Xem Markdown gốc</summary><pre class="mt-3" style="white-space:pre-wrap">{{ $document->markdown }}</pre></details>
    @unless($busy)
        <form method="post" action="{{ route('management.documents.destroy', $document->id) }}" onsubmit="return confirm('Xóa tài liệu và chỉ mục tìm kiếm?')">
            @csrf @method('DELETE')
            <button class="btn btn-outline-danger" type="submit">Xóa tài liệu</button>
        </form>
    @endunless
    <template id="document-chunk-template">
        <article class="order-card mb-3" data-chunk>
            <label class="form-label w-100">Đề mục<input class="form-control w-100" name="chunks[__INDEX__][title]" maxlength="500" required></label>
            <label class="form-label w-100">Nội dung<textarea class="form-control w-100" name="chunks[__INDEX__][content]" rows="8" maxlength="12000" required></textarea></label>
            <input type="hidden" name="chunks[__INDEX__][line]" value="1">
            <div class="d-flex gap-2 flex-wrap mt-2">
                <button type="button" class="btn btn-outline-dark" data-split>Tách tại con trỏ trong nội dung</button>
                <button type="button" class="btn btn-outline-dark" data-merge>Gộp với phần trước</button>
                <button type="button" class="btn btn-outline-danger" data-remove>Xóa phần</button>
            </div>
        </article>
    </template>
</div>
<script>
(() => {
    const chunks = document.getElementById('document-chunks');
    let index = Math.max(-1, ...Array.from(chunks.querySelectorAll('textarea'), element => Number(element.name.match(/\[(\d+)\]/)[1]))) + 1;
    const add = () => {
        const holder = document.createElement('div');
        holder.innerHTML = document.getElementById('document-chunk-template').innerHTML.replaceAll('__INDEX__', index++);
        const chunk = holder.firstElementChild;
        chunks.append(chunk);
        return chunk;
    };
    document.getElementById('add-document-chunk').addEventListener('click', add);
    chunks.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        const chunk = button.closest('[data-chunk]');
        const content = chunk.querySelector('textarea');
        if (button.hasAttribute('data-remove')) {
            if (chunks.children.length > 1) chunk.remove();
        } else if (button.hasAttribute('data-merge')) {
            const previous = chunk.previousElementSibling;
            if (!previous) return;
            const target = previous.querySelector('textarea');
            const combined = target.value + '\n\n' + content.value;
            if (combined.length > 12000) return alert('Phần sau khi gộp quá dài.');
            target.value = combined;
            chunk.remove();
        } else if (button.hasAttribute('data-split')) {
            const position = content.selectionStart;
            const before = content.value.slice(0, position).trim();
            const after = content.value.slice(position).trim();
            if (!before || !after) return alert('Đặt con trỏ tại vị trí muốn tách trong nội dung.');
            const next = add();
            next.querySelector('input').value = chunk.querySelector('input').value;
            next.querySelector('textarea').value = after;
            next.querySelector('input[type=hidden]').value = chunk.querySelector('input[type=hidden]').value;
            content.value = before;
            chunk.after(next);
        }
    });
})();
</script>
@endsection
