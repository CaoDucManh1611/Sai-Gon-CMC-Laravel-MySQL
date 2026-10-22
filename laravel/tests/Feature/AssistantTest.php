<?php

namespace Tests\Feature;

use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRetrievedKnowledge(): void
    {
        $id = (string) Str::uuid();
        $version = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $id, 'title' => 'Hồ sơ mua hàng', 'filename' => 'ho-so.md',
            'markdown' => '# Hồ sơ mua hàng',
            'chunks' => json_encode([['title' => 'Tra cứu', 'content' => 'Đăng nhập để xem báo giá, hợp đồng, đơn hàng và công nợ trong tài khoản.', 'line' => 3]], JSON_UNESCAPED_UNICODE),
            'status' => 'ready', 'active' => true, 'index_version' => $version,
            'embedding_model' => config('rag.embedding_model'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake([
            config('rag.ollama_url').'/api/embed' => Http::response(['embeddings' => [[0.3, 0.4, 0.5]]]),
            config('rag.qdrant_url').'/collections/'.app(RagService::class)->collection().'/points/query' => Http::response([
                'result' => ['points' => [['score' => 0.9, 'payload' => ['document_id' => $id, 'version' => $version, 'chunk_index' => 0]]]],
            ]),
        ]);
    }

    public function test_customer_knowledge_file_has_the_expected_schema(): void
    {
        $path = resource_path('ai/chatbot_knowledge.vi.json');
        $this->assertFileExists($path);

        $json = file_get_contents($path);
        $this->assertIsString($json);

        $knowledge = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $knowledge['schema_version']);
        $this->assertSame('vi', $knowledge['locale']);
        $this->assertSame('customer', $knowledge['audience']);
        $this->assertIsArray($knowledge['chunks']);
        $this->assertNotEmpty($knowledge['chunks']);
        $this->assertTrue(array_is_list($knowledge['chunks']));

        foreach ($knowledge['chunks'] as $chunk) {
            foreach (['id', 'topic', 'title', 'content'] as $field) {
                $this->assertIsString($chunk[$field]);
                $this->assertNotSame('', trim($chunk[$field]));
            }

            $this->assertIsArray($chunk['sources']);
            $this->assertNotEmpty($chunk['sources']);
            $this->assertTrue(array_is_list($chunk['sources']));

            foreach ($chunk['sources'] as $source) {
                $this->assertIsString($source);
                $this->assertNotSame('', trim($source));
            }
        }
    }

    public function test_assistant_uses_configured_groq_without_exposing_key(): void
    {
        $this->seed();
        DB::table('products')->where('code', 'PCB40')->update(['price' => 123456]);
        config(['services.groq.key' => 'test-secret', 'services.groq.model' => 'openai/gpt-oss-20b']);
        Http::preventStrayRequests();
        Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Hãy xem xi măng PCB40.']]],
        ])]);
        $this->fakeRetrievedKnowledge();
        $response = $this->post('/assistant', ['question' => 'Nên chọn xi măng nào cho công trình, cho tôi xem giá và tư vấn kỹ thuật?']);
        $response->assertOk()->assertSee('Hãy xem xi măng PCB40.')->assertDontSee('test-secret');
        Http::assertSent(function ($request): bool {
            $prompt = ($request['messages'][0]['content'] ?? '').($request['messages'][1]['content'] ?? '');

            return $request->hasHeader('Authorization', 'Bearer test-secret')
                && $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && ($request['model'] ?? null) === 'openai/gpt-oss-20b'
                && str_contains($prompt, 'Đăng nhập để xem báo giá, hợp đồng, đơn hàng và công nợ trong tài khoản.')
                && str_contains($prompt, 'hỏi một câu làm rõ nếu cần')
                && str_contains($prompt, 'Xưng hô mình/bạn')
                && str_contains($prompt, 'Không chào lại ở mỗi lượt')
                && str_contains($prompt, 'Xi măng PCB40')
                && str_contains($prompt, '123.456 đồng/bao');
        });
    }

    public function test_assistant_searches_local_catalog_when_groq_key_is_missing(): void
    {
        $this->seed();
        config(['services.groq.key' => null]);
        Http::preventStrayRequests();

        $this->postJson('/assistant', ['question' => 'Cho tôi báo giá xi măng PCB40'])
            ->assertOk()
            ->assertJsonPath('reply', fn (string $reply): bool => str_contains($reply, 'Xi măng PCB40')
                && str_contains($reply, 'Giá trên đây chỉ mang tính tham khảo.'));

        Http::assertNothingSent();
    }

    public function test_assistant_rejects_unrelated_questions_before_calling_groq(): void
    {
        config(['services.groq.key' => 'test-secret']);
        Http::preventStrayRequests();

        $this->postJson('/assistant', ['question' => 'Viết một bài thơ về tình yêu'])
            ->assertOk()
            ->assertJsonPath('reply', fn (string $reply): bool => str_contains($reply, 'chỉ hỗ trợ tư vấn vật liệu xây dựng'));

        Http::assertNothingSent();
    }

    public function test_assistant_answers_greetings_without_calling_groq(): void
    {
        config(['services.groq.key' => 'test-secret']);
        Http::preventStrayRequests();

        $this->postJson('/assistant', ['question' => 'Xin chào!'])
            ->assertOk()
            ->assertJsonPath('reply', fn (string $reply): bool => str_contains($reply, 'Chào bạn'));

        Http::assertNothingSent();
    }

    public function test_assistant_uses_customer_knowledge_for_account_cart_and_debt_questions(): void
    {
        config(['services.groq.key' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Hãy mở mục tương ứng trong tài khoản cửa hàng.']]],
        ])]);
        $this->fakeRetrievedKnowledge();

        foreach ([
            'Làm sao tạo tài khoản?',
            'Đăng nhập như thế nào?',
            'Tôi xem công nợ ở đâu?',
            'Làm thế nào thêm vào giỏ?',
        ] as $question) {
            $this->postJson('/assistant', ['question' => $question])
                ->assertOk()
                ->assertJsonPath('reply', 'Hãy mở mục tương ứng trong tài khoản cửa hàng.')
                ->assertJsonPath('sources.0.title', 'Hồ sơ mua hàng');
        }

        Http::assertSentCount(12);
    }

    public function test_assistant_rejects_short_questions_without_calling_groq(): void
    {
        config(['services.groq.key' => 'test-secret']);
        Http::preventStrayRequests();

        $this->postJson('/assistant', ['question' => 'AI'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('question');

        Http::assertNothingSent();
    }

    public function test_assistant_returns_fallback_when_groq_request_fails(): void
    {
        config(['services.groq.key' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([], 503)]);
        $this->fakeRetrievedKnowledge();

        $this->postJson('/assistant', ['question' => 'Tôi xem báo giá ở đâu?'])
            ->assertOk()
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'Đăng nhập để xem báo giá'))
            ->assertJsonPath('sources.0.title', 'Hồ sơ mua hàng');

        Http::assertSentCount(3);
    }
}
