<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GroqSettings;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiSetupTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'gsk_test_only_not_a_real_api_key_123456';

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_setup_and_writes_require_admin(): void
    {
        $this->get('/management/ai/setup')->assertRedirectToRoute('login');
        $this->put('/management/ai/setup')->assertRedirectToRoute('login');
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $this->actingAs($sales)->get('/management/ai/setup')->assertForbidden();
        $this->put('/management/ai/setup')->assertForbidden();
        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_admin_saves_encrypted_key_without_rendering_or_flashing_it(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->admin())->put('/management/ai/setup', [
            'api_key' => self::KEY, 'model' => 'openai/gpt-oss-20b', 'action' => 'save',
        ])->assertRedirectToRoute('management.ai-setup')->assertSessionHas('success');
        $stored = DB::table('ai_settings')->where('id', 1)->first();
        $this->assertNotSame(self::KEY, $stored->api_key);
        $this->assertSame(self::KEY, Crypt::decryptString($stored->api_key));
        $this->get('/management/ai/setup')->assertOk()->assertSee('Đã có API key')->assertDontSee(self::KEY);
        $this->assertArrayNotHasKey('api_key', session()->getOldInput());
        Http::assertNothingSent();
    }

    public function test_validation_does_not_flash_secret_into_session(): void
    {
        $this->actingAs($this->admin())->from('/management/ai/setup')->put('/management/ai/setup', [
            'api_key' => self::KEY, 'model' => 'invalid model!', 'action' => 'save',
        ])->assertSessionHasErrors('model')->assertSessionMissing('_old_input.api_key');
        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_blank_key_preserves_existing_secret_when_changing_model(): void
    {
        app(GroqSettings::class)->save(self::KEY, 'old-model');
        $this->actingAs($this->admin())->put('/management/ai/setup', [
            'api_key' => '', 'model' => 'new-model', 'action' => 'save',
        ])->assertRedirectToRoute('management.ai-setup');
        $this->assertSame(['key' => self::KEY, 'model' => 'new-model'], app(GroqSettings::class)->credentials());
    }

    public function test_clear_key_disables_generation_even_with_environment_key(): void
    {
        config(['services.groq.key' => 'environment-key']);
        app(GroqSettings::class)->save(self::KEY, 'openai/gpt-oss-20b');
        $this->actingAs($this->admin())->put('/management/ai/setup', [
            'model' => 'openai/gpt-oss-20b', 'action' => 'clear',
        ])->assertRedirectToRoute('management.ai-setup');
        $this->assertNull(app(GroqSettings::class)->credentials()['key']);
        $this->assertDatabaseHas('ai_settings', ['id' => 1, 'api_key' => null]);
    }

    public function test_connection_probe_uses_unsaved_key_and_model_without_saving_them(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => []])]);
        $this->actingAs($this->admin())->putJson('/management/ai/setup', [
            'api_key' => self::KEY, 'model' => 'openai/gpt-oss-20b', 'action' => 'test',
        ])->assertOk()->assertJsonPath('success', true)->assertDontSee(self::KEY);
        $this->assertDatabaseCount('ai_settings', 0);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $request['model'] === 'openai/gpt-oss-20b');
        $this->assertArrayNotHasKey('api_key', session()->getOldInput());
    }

    public function test_rejected_key_does_not_expose_provider_response_or_secret(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(['error' => self::KEY], 401)]);
        $this->actingAs($this->admin())->putJson('/management/ai/setup', [
            'api_key' => self::KEY, 'model' => 'openai/gpt-oss-20b', 'action' => 'test',
        ])->assertOk()->assertJsonPath('success', false)->assertDontSee(self::KEY)
            ->assertJsonPath('message', 'Groq từ chối API key. Kiểm tra hoặc tạo lại khóa trong tài khoản Groq.');
        Http::assertSentCount(1);
    }

    public function test_missing_key_is_reported_without_contacting_groq(): void
    {
        config(['services.groq.key' => null]);
        Http::preventStrayRequests();
        $this->actingAs($this->admin())->put('/management/ai/setup', [
            'model' => 'openai/gpt-oss-20b', 'action' => 'test',
        ])->assertSessionHasErrors('api_key');
        Http::assertNothingSent();
    }

    public function test_chatbot_uses_admin_key_and_model_instead_of_environment_values(): void
    {
        app(GroqSettings::class)->save(self::KEY, 'admin-model');
        config(['services.groq.key' => 'environment-key', 'services.groq.model' => 'environment-model']);
        $id = (string) Str::uuid();
        $version = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $id, 'title' => 'Hướng dẫn', 'filename' => 'huong-dan.md', 'markdown' => '# Hướng dẫn',
            'chunks' => json_encode([['title' => 'Đăng ký', 'content' => 'Mở trang đăng ký tài khoản.', 'line' => 3]]),
            'status' => 'ready', 'active' => true, 'index_version' => $version,
            'embedding_model' => config('rag.embedding_model'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake([
            config('rag.ollama_url').'/api/embed' => Http::response(['embeddings' => [[0.3, 0.4, 0.5]]]),
            config('rag.qdrant_url').'/collections/'.app(RagService::class)->collection().'/points/query' => Http::response([
                'result' => ['points' => [['score' => 0.8, 'payload' => ['document_id' => $id, 'version' => $version, 'chunk_index' => 0]]]],
            ]),
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Bạn hãy mở trang đăng ký [1].']]],
            ]),
        ]);
        $this->postJson('/assistant', ['question' => 'Tạo tài khoản thế nào?'])->assertOk()
            ->assertJsonPath('reply', 'Bạn hãy mở trang đăng ký.')->assertDontSee(self::KEY);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY) && $request['model'] === 'admin-model');
    }
}
