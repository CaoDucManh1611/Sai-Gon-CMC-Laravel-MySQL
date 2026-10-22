<?php

namespace App\Jobs;

use App\Services\RagService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class IndexKnowledgeDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $documentId, public string $version)
    {
        $this->onConnection('rag')->onQueue('rag');
    }

    public function handle(RagService $rag): void
    {
        File::ensureDirectoryExists(config('backup.path'));
        $handle = fopen(config('backup.path').'/.operation.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_SH | LOCK_NB)) {
            if ($handle) {
                fclose($handle);
            }
            $this->release(15);

            return;
        }
        try {
            $this->index($rag);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function index(RagService $rag): void
    {
        $claimed = DB::table('knowledge_documents')->where('id', $this->documentId)
            ->where('index_version', $this->version)->where('status', 'queued')
            ->update(['status' => 'indexing', 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        try {
            $document = DB::table('knowledge_documents')->where('id', $this->documentId)->first();
            $rag->index($document);
            DB::table('knowledge_documents')->where('id', $this->documentId)
                ->where('index_version', $this->version)->where('status', 'indexing')->update([
                    'status' => 'ready', 'embedding_model' => config('rag.embedding_model'),
                    'indexed_at' => now(), 'error' => null, 'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            $this->failed($exception);
            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('knowledge_documents')->where('id', $this->documentId)
            ->where('index_version', $this->version)->whereIn('status', ['queued', 'indexing'])->update([
                'status' => 'error', 'updated_at' => now(),
                'error' => 'Chưa lập chỉ mục được. Kiểm tra dịch vụ embedding/vector rồi bấm duyệt và lập chỉ mục lại.',
            ]);
    }
}
