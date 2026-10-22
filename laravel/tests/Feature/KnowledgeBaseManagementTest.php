<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $knowledgePath;

    private string $originalKnowledge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->knowledgePath = resource_path('ai/chatbot_knowledge.vi.json');
        $this->originalKnowledge = file_get_contents($this->knowledgePath);
    }

    protected function tearDown(): void
    {
        file_put_contents($this->knowledgePath, $this->originalKnowledge, LOCK_EX);

        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login_when_opening_knowledge_management(): void
    {
        $this->get('/management/knowledge')->assertRedirectToRoute('login');
    }

    public function test_non_admin_is_forbidden_from_knowledge_management(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);

        $this->actingAs($sales)->get('/management/knowledge')->assertForbidden();
    }

    public function test_admin_can_view_customer_knowledge(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $response = $this->actingAs($admin)->get('/management/knowledge');

        $response
            ->assertOk()
            ->assertSee('Kho tri thức khách hàng')
            ->assertSee('Xem cửa hàng và danh mục');
        $this->assertStringContainsString('data-knowledge-index="0"', $response->getContent());
    }

    public function test_admin_can_save_customer_knowledge_chunks(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $chunk = [
            'id' => 'test.materials',
            'topic' => 'materials',
            'title' => 'Tư vấn vật liệu',
            'content' => 'Khách có thể hỏi nhân viên để được tư vấn.',
            'sources' => "routes/web.php\napp/Http/Controllers/AssistantController.php",
        ];

        $this->from('/management/knowledge')->actingAs($admin)->put('/management/knowledge', ['chunks' => [$chunk]])
            ->assertRedirect('/management/knowledge')
            ->assertSessionHas('success');

        $saved = json_decode(file_get_contents($this->knowledgePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $saved['schema_version']);
        $this->assertSame('vi', $saved['locale']);
        $this->assertSame('customer', $saved['audience']);
        $this->assertSame([
            'id' => 'test.materials',
            'topic' => 'materials',
            'title' => 'Tư vấn vật liệu',
            'content' => 'Khách có thể hỏi nhân viên để được tư vấn.',
            'sources' => ['routes/web.php', 'app/Http/Controllers/AssistantController.php'],
        ], $saved['chunks'][0]);
    }

    public function test_admin_can_reopen_editor_after_invalid_payload(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->from('/management/knowledge')->actingAs($admin)->put('/management/knowledge', ['chunks' => 'invalid'])
            ->assertRedirect('/management/knowledge')
            ->assertSessionHasErrors('chunks');

        $this->get('/management/knowledge')->assertOk()->assertSee('Kho tri thức khách hàng');
    }

    public function test_duplicate_chunk_ids_are_rejected_without_changing_knowledge(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $chunks = [
            ['id' => 'same.id', 'topic' => 'topic', 'title' => 'Một', 'content' => 'Nội dung một', 'sources' => 'routes/web.php'],
            ['id' => 'same.id', 'topic' => 'topic', 'title' => 'Hai', 'content' => 'Nội dung hai', 'sources' => 'routes/web.php'],
        ];

        $this->from('/management/knowledge')->actingAs($admin)->put('/management/knowledge', ['chunks' => $chunks])
            ->assertRedirect('/management/knowledge')
            ->assertSessionHasErrors('chunks.1.id');

        $this->assertSame($this->originalKnowledge, file_get_contents($this->knowledgePath));
    }
}
