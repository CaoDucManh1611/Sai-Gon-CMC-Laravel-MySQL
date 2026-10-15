<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_assistant_uses_configured_gemini_without_exposing_key(): void
    {
        $this->seed();
        DB::table('products')->where('code', 'PCB40')->update(['price' => 123456]);
        config(['services.gemini.key' => 'test-secret', 'services.gemini.model' => 'gemini-2.5-flash']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Hãy xem xi măng PCB40.']]]]],
        ])]);
        $response = $this->post('/assistant', ['question' => 'Nên chọn xi măng nào cho công trình?']);
        $response->assertOk()->assertSee('Hãy xem xi măng PCB40.')->assertDontSee('test-secret');
        Http::assertSent(function ($request): bool {
            $prompt = $request['contents'][0]['parts'][0]['text'] ?? '';

            return $request->hasHeader('x-goog-api-key', 'test-secret')
                && str_contains($request->url(), 'gemini-2.5-flash:generateContent')
                && str_contains($prompt, 'Đăng nhập để xem báo giá, hợp đồng, đơn hàng và công nợ trong tài khoản.')
                && str_contains($prompt, 'Nếu chưa đủ thông tin về công trình, hãy hỏi thêm câu hỏi làm rõ trước khi gợi ý.')
                && str_contains($prompt, 'Xi măng PCB40')
                && str_contains($prompt, '123.456 đồng/bao');
        });
    }

    public function test_assistant_returns_fallback_when_gemini_key_is_missing(): void
    {
        config(['services.gemini.key' => null]);

        $this->postJson('/assistant', ['question' => 'Tôi xem báo giá ở đâu?'])
            ->assertOk()
            ->assertExactJson([
                'reply' => 'Trợ lý AI chưa được cấu hình. Vui lòng liên hệ nhân viên để được tư vấn.',
            ]);

        Http::assertNothingSent();
    }

    public function test_assistant_returns_fallback_when_gemini_request_fails(): void
    {
        config(['services.gemini.key' => 'test-secret']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 503)]);

        $this->postJson('/assistant', ['question' => 'Tôi xem báo giá ở đâu?'])
            ->assertOk()
            ->assertExactJson([
                'reply' => 'Hiện chưa kết nối được trợ lý AI. Vui lòng thử lại hoặc liên hệ nhân viên.',
            ]);

        Http::assertSentCount(1);
    }
}
