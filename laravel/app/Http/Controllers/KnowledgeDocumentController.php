<?php

namespace App\Http\Controllers;

use App\Jobs\IndexKnowledgeDocument;
use App\Services\RagService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class KnowledgeDocumentController extends Controller
{
    public function index(RagService $rag): View
    {
        return view('staff.documents', [
            'documents' => $this->documents(),
            'health' => $rag->health(),
        ]);
    }

    public function store(Request $request, RagService $rag): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'document' => ['required', 'file', 'extensions:md,markdown', 'max:1024'],
        ]);
        $markdown = file_get_contents($request->file('document')->getRealPath());
        try {
            $chunks = $rag->prepare($markdown);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['document' => 'Không thể phân tích ngữ nghĩa. Hãy kiểm tra dịch vụ embedding rồi tải lại.']);
        }
        $id = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $id, 'title' => $data['title'],
            'filename' => mb_substr(basename($request->file('document')->getClientOriginalName()), 0, 255),
            'markdown' => $markdown, 'chunks' => json_encode($chunks, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'draft', 'active' => true, 'uploaded_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return to_route('management.documents.show', $id)->with('success', 'Đã chia tài liệu. Kiểm tra các phần rồi duyệt để chatbot sử dụng.');
    }

    public function show(string $id): View
    {
        $document = DB::table('knowledge_documents')->where('id', $id)->first();
        abort_unless($document, 404);

        return view('staff.document', [
            'document' => $document,
            'chunks' => json_decode($document->chunks, true, 512, JSON_THROW_ON_ERROR),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'chunks' => ['required', 'array', 'min:1', 'max:200'],
            'chunks.*' => ['required', 'array:title,content,line'],
            'chunks.*.title' => ['required', 'string', 'max:500'],
            'chunks.*.content' => ['required', 'string', 'max:12000'],
            'chunks.*.line' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:save,index'],
        ]);
        if (strlen(json_encode($data['chunks'])) > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['chunks' => 'Tổng nội dung quá lớn. Hãy chia thành nhiều tài liệu.']);
        }
        DB::transaction(function () use ($data, $id): void {
            $document = DB::table('knowledge_documents')->where('id', $id)->lockForUpdate()->first();
            abort_unless($document, 404);
            abort_if(in_array($document->status, ['queued', 'indexing'], true), 409, 'Tài liệu đang được lập chỉ mục.');
            $version = (string) Str::uuid();
            DB::table('knowledge_documents')->where('id', $id)->update([
                'title' => $data['title'],
                'chunks' => json_encode(array_values($data['chunks']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => $data['action'] === 'index' ? 'queued' : 'draft',
                'index_version' => $version, 'error' => null, 'updated_at' => now(),
            ]);
            if ($data['action'] === 'index') {
                IndexKnowledgeDocument::dispatch($id, $version)->afterCommit();
            }
        });

        return back()->with('success', $data['action'] === 'index' ? 'Đã duyệt. Đang chờ tạo embedding và lập chỉ mục.' : 'Đã lưu bản nháp. Duyệt và lập chỉ mục để chatbot dùng nội dung mới.');
    }

    public function toggle(string $id): RedirectResponse
    {
        DB::transaction(function () use ($id): void {
            $document = DB::table('knowledge_documents')->where('id', $id)->lockForUpdate()->first();
            abort_unless($document, 404);
            DB::table('knowledge_documents')->where('id', $id)->update(['active' => ! $document->active, 'updated_at' => now()]);
        });

        return back()->with('success', 'Đã thay đổi trạng thái sử dụng tài liệu.');
    }

    public function destroy(string $id, RagService $rag): RedirectResponse
    {
        try {
            DB::transaction(function () use ($id, $rag): void {
                $document = DB::table('knowledge_documents')->where('id', $id)->lockForUpdate()->first();
                abort_unless($document, 404);
                abort_if(in_array($document->status, ['queued', 'indexing'], true), 409, 'Hãy chờ lập chỉ mục hoàn tất trước khi xóa.');
                DB::table('knowledge_documents')->where('id', $id)->update(['active' => false]);
                $rag->deleteVectors($id);
                DB::table('knowledge_documents')->where('id', $id)->delete();
            });
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return back()->withErrors(['document' => 'Chưa xóa được chỉ mục vector. Tài liệu được giữ lại; hãy kiểm tra kết nối và thử lại.']);
        }

        return to_route('management.documents.index')->with('success', 'Đã xóa tài liệu và chỉ mục vector.');
    }

    public function search(Request $request, RagService $rag): View
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:1000']]);
        try {
            $results = $rag->search($data['question']);
        } catch (\Throwable $exception) {
            report($exception);
            $results = [];
            session()->flash('error', 'Không truy xuất được. Kiểm tra dịch vụ embedding và vector.');
        }

        return view('staff.documents', [
            'documents' => $this->documents(),
            'health' => $rag->health(), 'results' => $results, 'question' => $data['question'],
        ]);
    }

    public function source(Request $request, string $id): View
    {
        $data = $request->validate(['chunk' => ['required', 'integer', 'min:0']]);
        $document = DB::table('knowledge_documents')->where('id', $id)->where('active', true)->where('status', 'ready')->first();
        abort_unless($document, 404);
        $chunks = json_decode($document->chunks, true, 512, JSON_THROW_ON_ERROR);
        $chunk = $chunks[$data['chunk']] ?? null;
        abort_unless($chunk, 404);

        return view('store.knowledge-source', compact('document', 'chunk'));
    }

    private function documents(): LengthAwarePaginator
    {
        return DB::table('knowledge_documents')->latest()->paginate(10, [
            'id', 'title', 'filename', 'chunks', 'status', 'active', 'error', 'indexed_at', 'created_at',
        ]);
    }
}
