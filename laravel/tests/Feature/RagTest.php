<?php

namespace Tests\Feature;

use App\Jobs\IndexKnowledgeDocument;
use App\Models\User;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RagTest extends TestCase
{
    use RefreshDatabase;

    private function document(array $overrides = []): object
    {
        $id = (string) Str::uuid();
        DB::table('knowledge_documents')->insert(array_merge([
            'id' => $id, 'title' => 'Chính sách đổi trả', 'filename' => 'doi-tra.md',
            'markdown' => '# Đổi trả',
            'chunks' => json_encode([['title' => 'Điều kiện', 'content' => 'Hàng cần còn nguyên bao bì để được xem xét đổi trả.', 'line' => 3]], JSON_UNESCAPED_UNICODE),
            'status' => 'ready', 'active' => true, 'index_version' => (string) Str::uuid(),
            'embedding_model' => config('rag.embedding_model'), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return DB::table('knowledge_documents')->where('id', $id)->first();
    }

    private function vectorFakes(array $points = []): void
    {
        Http::preventStrayRequests();
        $base = config('rag.qdrant_url').'/collections/'.app(RagService::class)->collection();
        Http::fake([
            config('rag.ollama_url').'/api/embed' => fn ($request) => Http::response([
                'embeddings' => array_fill(0, count($request['input']), [0.3, 0.4, 0.5]),
            ]),
            $base => Http::response(['result' => []]),
            $base.'/points?wait=true' => Http::response(['status' => 'ok']),
            $base.'/points/delete?wait=true' => Http::response(['status' => 'ok']),
            $base.'/points/query' => Http::response(['result' => ['points' => $points]]),
        ]);
    }

    public function test_only_admin_can_upload_and_approve_documents(): void
    {
        $this->post('/management/knowledge/documents')->assertRedirectToRoute('login');
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $document = $this->document();
        $this->actingAs($sales)->post('/management/knowledge/documents')->assertForbidden();
        $this->put('/management/knowledge/documents/'.$document->id)->assertForbidden();
        $this->delete('/management/knowledge/documents/'.$document->id)->assertForbidden();
        $this->assertDatabaseCount('knowledge_documents', 1);
    }

    public function test_admin_dashboard_shows_upload_service_readiness_and_drafts(): void
    {
        $this->document(['status' => 'draft']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::preventStrayRequests();
        Http::fake([
            config('rag.ollama_url').'/api/tags' => Http::response(['models' => [['name' => 'bge-m3:latest']]]),
            config('rag.qdrant_url').'/healthz' => Http::response('healthz check passed'),
        ]);
        $this->actingAs($admin)->get('/management/knowledge/documents')->assertOk()
            ->assertSee('Thêm tài liệu Markdown')->assertSee('Chờ duyệt')->assertSee('Embedding: sẵn sàng');
        Http::assertSentCount(2);
    }

    public function test_admin_can_delete_document_and_its_vector_points(): void
    {
        $this->vectorFakes();
        $document = $this->document();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->delete('/management/knowledge/documents/'.$document->id)
            ->assertRedirectToRoute('management.documents.index');
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $document->id]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/points/delete')
            && $request['filter']['must'][0]['match']['value'] === $document->id);
    }

    public function test_failed_vector_deletion_preserves_document_and_shows_error(): void
    {
        $document = $this->document();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::preventStrayRequests();
        Http::fake([config('rag.qdrant_url').'/collections/'.app(RagService::class)->collection().'/points/delete?wait=true' => Http::response([], 503)]);
        $this->from('/management/knowledge/documents/'.$document->id)->actingAs($admin)
            ->delete('/management/knowledge/documents/'.$document->id)->assertRedirect()->assertSessionHasErrors('document');
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'active' => true]);
        Http::assertSentCount(1);
    }

    public function test_document_cannot_be_edited_or_deleted_during_indexing(): void
    {
        $document = $this->document(['status' => 'indexing']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::preventStrayRequests();
        $this->actingAs($admin)->put('/management/knowledge/documents/'.$document->id, [
            'title' => 'Thay đổi', 'chunks' => json_decode($document->chunks, true), 'action' => 'save',
        ])->assertStatus(409);
        $this->delete('/management/knowledge/documents/'.$document->id)->assertStatus(409);
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'title' => $document->title, 'status' => 'indexing']);
        Http::assertNothingSent();
    }

    public function test_stale_queue_job_cannot_publish_a_newer_document_revision(): void
    {
        $document = $this->document(['status' => 'queued']);
        Http::preventStrayRequests();
        (new IndexKnowledgeDocument($document->id, 'old-version'))->handle(app(RagService::class));
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'queued']);
        Http::assertNothingSent();
    }

    public function test_markdown_upload_creates_escaped_editable_draft_without_publishing(): void
    {
        Http::preventStrayRequests();
        Queue::fake([IndexKnowledgeDocument::class]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $file = UploadedFile::fake()->createWithContent('huong-dan.md', "# Quy trình\n\n## Đổi trả\n\n<script>alert('x')</script>\n\nGiữ nguyên bao bì.");
        $response = $this->actingAs($admin)->post('/management/knowledge/documents', ['title' => 'Hướng dẫn', 'document' => $file]);
        $document = DB::table('knowledge_documents')->first();
        $response->assertRedirectToRoute('management.documents.show', $document->id);
        $this->assertSame('draft', $document->status);
        $this->assertSame('Quy trình / Đổi trả', json_decode($document->chunks, true)[0]['title']);
        $this->get('/management/knowledge/documents/'.$document->id)->assertOk()
            ->assertSee('&lt;script&gt;', false)->assertDontSee("<script>alert('x')</script>", false);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_non_markdown_and_binary_uploads_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        foreach ([
            UploadedFile::fake()->createWithContent('file.php', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('file.md', "bad\0data"),
        ] as $file) {
            $this->actingAs($admin)->post('/management/knowledge/documents', ['title' => 'Tài liệu', 'document' => $file])
                ->assertSessionHasErrors('document');
        }
        $this->assertDatabaseCount('knowledge_documents', 0);
    }

    public function test_approval_queues_snapshot_and_indexing_publishes_vectors_with_provenance(): void
    {
        Queue::fake([IndexKnowledgeDocument::class]);
        $this->vectorFakes();
        $document = $this->document(['status' => 'draft']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->put('/management/knowledge/documents/'.$document->id, [
            'title' => $document->title, 'chunks' => json_decode($document->chunks, true), 'action' => 'index',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'queued']);
        $saved = DB::table('knowledge_documents')->where('id', $document->id)->first();
        Queue::assertPushed(IndexKnowledgeDocument::class, fn ($job) => $job->documentId === $document->id && $job->version === $saved->index_version);
        (new IndexKnowledgeDocument($document->id, $saved->index_version))->handle(app(RagService::class));
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'ready', 'error' => null]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/points?wait=true')
            && $request['points'][0]['payload']['document_id'] === $document->id
            && $request['points'][0]['payload']['version'] === $saved->index_version);
    }

    public function test_failed_embedding_preserves_draft_and_reports_retryable_error(): void
    {
        $document = $this->document(['status' => 'queued']);
        Http::preventStrayRequests();
        Http::fake([config('rag.ollama_url').'/api/embed' => Http::response([], 503)]);
        (new IndexKnowledgeDocument($document->id, $document->index_version))->handle(app(RagService::class));
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'status' => 'error', 'markdown' => '# Đổi trả']);
        Http::assertSentCount(1);
    }

    public function test_retrieval_ignores_inactive_drafts_stale_versions_and_duplicate_points(): void
    {
        $document = $this->document();
        $inactive = $this->document(['active' => false]);
        $draft = $this->document(['status' => 'draft']);
        $points = [];
        foreach ([
            [$inactive, $inactive->index_version], [$draft, $draft->index_version],
            [$document, 'old-version'], [$document, $document->index_version], [$document, $document->index_version],
        ] as [$item, $version]) {
            $points[] = ['score' => 0.8, 'payload' => ['document_id' => $item->id, 'version' => $version, 'chunk_index' => 0]];
        }
        $this->vectorFakes($points);
        $matches = app(RagService::class)->search('Hàng còn nguyên bao bì có đổi được không?');
        $this->assertCount(1, $matches);
        $this->assertSame('Điều kiện', $matches[0]['section']);
        $this->assertStringContainsString($document->id, $matches[0]['url']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/points/query')
            && $request['filter']['must'][0]['match']['any'] === [$document->id]);
    }

    public function test_assistant_uses_retrieved_content_and_exposes_only_actual_sources(): void
    {
        $document = $this->document();
        $this->vectorFakes([['score' => 0.85, 'payload' => ['document_id' => $document->id, 'version' => $document->index_version, 'chunk_index' => 0]]]);
        config(['services.groq.key' => 'test-secret']);
        Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Hàng cần còn nguyên bao bì [1].']]],
        ])]);
        $this->postJson('/assistant', ['question' => 'Hàng có cần nguyên bao bì không?'])
            ->assertOk()->assertJsonPath('reply', 'Hàng cần còn nguyên bao bì.')
            ->assertJsonPath('sources.0.title', $document->title)->assertDontSee('test-secret');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request['messages'][0]['role'] === 'system'
            && str_contains($request['messages'][1]['content'], 'Hàng cần còn nguyên bao bì')
            && ! str_contains($request['messages'][1]['content'], '=== TRI THỨC KHÁCH HÀNG ==='));
    }

    public function test_missing_key_returns_retrieved_excerpts(): void
    {
        $document = $this->document();
        $this->vectorFakes([['score' => 0.85, 'payload' => ['document_id' => $document->id, 'version' => $document->index_version, 'chunk_index' => 0]]]);
        config(['services.groq.key' => null]);
        $this->postJson('/assistant', ['question' => 'Điều kiện đổi trả?'])->assertOk()
            ->assertJsonPath('sources.0.title', $document->title)
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'còn nguyên bao bì'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
    }

    public function test_service_failure_is_not_a_hallucinated_answer(): void
    {
        $this->document();
        config(['services.groq.key' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake([config('rag.ollama_url').'/api/embed' => Http::response([], 503)]);
        $this->postJson('/assistant', ['question' => 'Điều kiện đổi trả?'])->assertOk()
            ->assertExactJson(['reply' => 'Hiện mình chưa tra cứu được tài liệu. Bạn thử lại sau một chút hoặc liên hệ nhân viên nhé.']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
    }

    public function test_embedding_cache_does_not_cache_document_visibility(): void
    {
        $document = $this->document();
        $this->vectorFakes([['score' => 0.85, 'payload' => ['document_id' => $document->id, 'version' => $document->index_version, 'chunk_index' => 0]]]);
        $rag = app(RagService::class);
        $this->assertCount(1, $rag->search('Điều kiện đổi trả?'));
        $this->assertCount(1, $rag->search('Điều kiện đổi trả?'));
        DB::table('knowledge_documents')->where('id', $document->id)->update(['active' => false]);
        $this->assertSame([], $rag->search('Điều kiện đổi trả?'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/embed') && $request['keep_alive'] === '30m');
    }

    public function test_public_source_is_escaped_and_unavailable_after_disabling_document(): void
    {
        $document = $this->document(['chunks' => json_encode([['title' => 'Đổi trả', 'content' => '<script>alert(1)</script>', 'line' => 3]])]);
        $url = '/assistant/sources/'.$document->id.'?chunk=0';
        $this->get($url)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->patch('/management/knowledge/documents/'.$document->id.'/toggle')->assertRedirect();
        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id, 'active' => false]);
    }
}
