<?php

namespace App\Console\Commands;

use App\Jobs\IndexKnowledgeDocument;
use App\Services\RagService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportKnowledge extends Command
{
    protected $signature = 'rag:import-legacy {--index : Approve and queue indexing of existing knowledge}';

    protected $description = 'Import existing customer knowledge as a Markdown document without changing the original JSON';

    public function handle(RagService $rag): int
    {
        if (DB::table('knowledge_documents')->where('filename', 'tri-thuc-cmc.md')->exists()) {
            $this->info('Tri thức cũ đã được nhập. Quản lý và lập chỉ mục tại trang admin.');

            return self::SUCCESS;
        }
        $legacy = json_decode(file_get_contents(resource_path('ai/chatbot_knowledge.vi.json')), true, 512, JSON_THROW_ON_ERROR);
        $markdown = "# Kho tri thức khách hàng\n\n".collect($legacy['chunks'])->map(fn ($chunk) => '## '.$chunk['title']."\n\n".$chunk['content'])->implode("\n\n");
        $id = (string) Str::uuid();
        $version = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $id, 'title' => 'Hướng dẫn khách hàng Sài Gòn CMC', 'filename' => 'tri-thuc-cmc.md',
            'markdown' => $markdown, 'chunks' => json_encode($rag->prepare($markdown), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => $this->option('index') ? 'queued' : 'draft', 'active' => true,
            'index_version' => $version, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($this->option('index')) {
            IndexKnowledgeDocument::dispatch($id, $version);
        }
        $this->info('Đã nhập tri thức hiện có thành tài liệu Markdown: '.$id);

        return self::SUCCESS;
    }
}
