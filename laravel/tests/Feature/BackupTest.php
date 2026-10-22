<?php

namespace Tests\Feature;

use App\Jobs\IndexKnowledgeDocument;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->temporary = storage_path('framework/testing/backup-'.Str::uuid());
        config([
            'backup.path' => $this->temporary.'/backups',
            'backup.roots' => ['private' => $this->temporary.'/private', 'public' => $this->temporary.'/public'],
            'backup.legacy_path' => $this->temporary.'/knowledge.json',
        ]);
        File::ensureDirectoryExists($this->temporary.'/private/contracts');
        File::ensureDirectoryExists($this->temporary.'/public');
        File::put(config('backup.legacy_path'), '{"chunks":[{"title":"Tri thức gốc"}]}');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active', 'password' => Hash::make('backup-test-password')]);
    }

    public function test_only_admin_can_create_upload_download_and_restore(): void
    {
        $id = (string) Str::uuid();
        $this->get('/management/backups')->assertRedirectToRoute('login');
        $this->post('/management/backups')->assertRedirectToRoute('login');
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $this->actingAs($sales)->get('/management/backups')->assertForbidden();
        $this->post('/management/backups')->assertForbidden();
        $this->post('/management/backups/upload')->assertForbidden();
        $this->get('/management/backups/'.$id.'/download')->assertForbidden();
        $this->post('/management/backups/'.$id.'/restore')->assertForbidden();
    }

    public function test_create_encrypted_backup_preview_and_download_include_business_data_and_files(): void
    {
        $admin = $this->admin();
        File::put($this->temporary.'/private/contracts/contract.pdf', 'private-contract-content');
        DB::table('ai_settings')->insert(['id' => 1, 'api_key' => Crypt::encryptString('private-api-key'), 'model' => 'model']);
        $this->actingAs($admin)->post('/management/backups')->assertRedirect();
        $service = app(BackupService::class);
        $backup = $service->listing()[0];
        $payload = $service->inspect($service->path($backup['id']));
        $this->assertSame('private-contract-content', base64_decode($payload['files']['private/contracts/contract.pdf']));
        $this->assertSame($admin->id, $payload['tables']['users'][0]['id']);
        $this->assertArrayNotHasKey('sessions', $payload['tables']);
        $this->assertStringNotContainsString('private-contract-content', File::get($service->path($backup['id'])));
        $this->get('/management/backups')->assertOk()->assertSee('Sao lưu &amp; khôi phục', false);
        $this->get('/management/backups/'.$backup['id'])->assertOk()->assertSee('Kiểm tra bản sao lưu');
        $this->get('/management/backups/'.$backup['id'].'/download')->assertOk()->assertDownload('cmc-'.$backup['id'].'.cmcbackup');
    }

    public function test_restore_replaces_records_and_files_and_creates_safety_copy(): void
    {
        $admin = $this->admin();
        $this->seed();
        Queue::fake([IndexKnowledgeDocument::class]);
        File::put($this->temporary.'/private/contracts/example.pdf', 'original-file');
        $documentId = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $documentId, 'title' => 'Chính sách', 'filename' => 'policy.md', 'markdown' => '# Chính sách',
            'chunks' => json_encode([['title' => 'Đổi trả', 'content' => 'Kiểm tra hàng', 'line' => 3]]),
            'status' => 'ready', 'active' => true, 'index_version' => (string) Str::uuid(),
        ]);
        $service = app(BackupService::class);
        $id = $service->create();
        DB::table('users')->where('id', $admin->id)->update(['name' => 'Tên đã thay đổi']);
        DB::table('products')->update(['price' => 999]);
        File::put($this->temporary.'/private/contracts/example.pdf', 'changed-file');
        File::put($this->temporary.'/private/contracts/new.pdf', 'new-file');
        File::put(config('backup.legacy_path'), '{"chunks":[]}');
        $this->actingAs($admin)->post('/management/backups/'.$id.'/restore', [
            'password' => 'backup-test-password', 'confirmation' => 'KHOI PHUC',
        ])->assertRedirectToRoute('login');
        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => $admin->name]);
        $this->assertDatabaseHas('products', ['code' => 'PCB40', 'price' => 92000]);
        $this->assertSame('original-file', File::get($this->temporary.'/private/contracts/example.pdf'));
        $this->assertFileDoesNotExist($this->temporary.'/private/contracts/new.pdf');
        $this->assertStringContainsString('Tri thức gốc', File::get(config('backup.legacy_path')));
        $this->assertCount(2, $service->listing());
        $reasons = array_map(fn ($item) => $service->inspect($service->path($item['id']))['reason'], $service->listing());
        $this->assertContains('before-restore', $reasons);
        $this->assertDatabaseHas('knowledge_documents', ['id' => $documentId, 'status' => 'queued']);
        Queue::assertPushed(IndexKnowledgeDocument::class, fn ($job) => $job->documentId === $documentId);
    }

    public function test_restore_requires_current_password_and_exact_confirmation(): void
    {
        $admin = $this->admin();
        $id = app(BackupService::class)->create();
        $this->actingAs($admin)->post('/management/backups/'.$id.'/restore', [
            'password' => 'wrong', 'confirmation' => 'yes',
        ])->assertSessionHasErrors(['password', 'confirmation'])->assertSessionMissing('_old_input.password');
        $this->assertCount(1, app(BackupService::class)->listing());
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_uploaded_backup_is_validated_before_import_and_does_not_restore(): void
    {
        $admin = $this->admin();
        $service = app(BackupService::class);
        $id = $service->create();
        DB::table('users')->where('id', $admin->id)->update(['name' => 'Giữ nguyên']);
        $this->actingAs($admin)->post('/management/backups/upload', [
            'backup' => UploadedFile::fake()->createWithContent('saved.cmcbackup', File::get($service->path($id))),
        ])->assertRedirect();
        $this->assertCount(2, $service->listing());
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => 'Giữ nguyên']);
        $this->post('/management/backups/upload', [
            'backup' => UploadedFile::fake()->createWithContent('bad.cmcbackup', 'invalid'),
        ])->assertSessionHasErrors('backup');
        $this->assertCount(2, $service->listing());
    }

    public function test_tampered_backup_and_schema_mismatch_are_rejected(): void
    {
        $this->admin();
        $service = app(BackupService::class);
        $id = $service->create();
        $payload = $service->inspect($service->path($id));
        $payload['schema']['users'][0]['type'] = 'different-type';
        File::put($service->path($id), gzencode(Crypt::encryptString(json_encode($payload))));
        $this->expectExceptionMessage('không tương thích');
        $service->inspect($service->path($id));
    }

    public function test_path_traversal_in_archive_is_rejected_before_writing(): void
    {
        $this->admin();
        $service = app(BackupService::class);
        $id = $service->create();
        $payload = $service->inspect($service->path($id));
        $payload['files']['private/../../escape'] = base64_encode('bad');
        File::put($service->path($id), gzencode(Crypt::encryptString(json_encode($payload))));
        $this->expectExceptionMessage('Đường dẫn');
        $service->inspect($service->path($id));
    }

    public function test_invalid_restored_reference_rolls_back_database_and_preserves_files(): void
    {
        $admin = $this->admin();
        $this->seed();
        File::put($this->temporary.'/private/contracts/keep.pdf', 'keep');
        $service = app(BackupService::class);
        $id = $service->create();
        $payload = $service->inspect($service->path($id));
        $payload['tables']['products'][0]['category_id'] = 999999;
        File::put($service->path($id), gzencode(Crypt::encryptString(json_encode($payload))));
        DB::table('users')->where('id', $admin->id)->update(['name' => 'Giữ nguyên']);
        $this->actingAs($admin)->post('/management/backups/'.$id.'/restore', [
            'password' => 'backup-test-password', 'confirmation' => 'KHOI PHUC',
        ])->assertSessionHasErrors('backup');
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => 'Giữ nguyên']);
        $this->assertSame('keep', File::get($this->temporary.'/private/contracts/keep.pdf'));
        $this->assertAuthenticated();
    }

    public function test_restore_is_blocked_while_indexing_is_active(): void
    {
        $admin = $this->admin();
        $id = app(BackupService::class)->create();
        DB::table('knowledge_documents')->insert([
            'id' => (string) Str::uuid(), 'title' => 'Đang xử lý', 'filename' => 'busy.md',
            'markdown' => 'Nội dung', 'chunks' => '[]', 'status' => 'indexing',
        ]);
        $this->actingAs($admin)->post('/management/backups/'.$id.'/restore', [
            'password' => 'backup-test-password', 'confirmation' => 'KHOI PHUC',
        ])->assertSessionHasErrors('backup');
        $this->assertCount(1, app(BackupService::class)->listing());
    }

    public function test_exclusive_operation_temporarily_blocks_normal_web_requests(): void
    {
        app(BackupService::class)->exclusive(function (): void {
            $this->get('/login')->assertStatus(503)->assertHeader('Retry-After', '15');
        });
        $this->get('/login')->assertOk();
    }
}
