<?php

namespace App\Services;

use App\Jobs\IndexKnowledgeDocument;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BackupService
{
    private const EXCLUDED = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    private function write(string $path, string $contents): void
    {
        if (File::put($path, $contents, true) !== strlen($contents)) {
            throw new RuntimeException('Không ghi đủ dữ liệu. Hãy kiểm tra dung lượng và quyền ghi.');
        }
    }

    public function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Mã bản sao lưu không hợp lệ.');
        }

        return config('backup.path').DIRECTORY_SEPARATOR.$id.'.cmcbackup';
    }

    public function listing(): array
    {
        File::ensureDirectoryExists(config('backup.path'));
        $items = [];
        foreach (File::files(config('backup.path')) as $file) {
            if ($file->getExtension() !== 'cmcbackup' || ! Str::isUuid($file->getBasename('.cmcbackup'))) {
                continue;
            }
            $items[] = ['id' => $file->getBasename('.cmcbackup'), 'size' => $file->getSize(), 'time' => $file->getMTime()];
        }
        usort($items, fn ($a, $b) => $b['time'] <=> $a['time']);

        return $items;
    }

    public function exclusive(callable $callback): mixed
    {
        File::ensureDirectoryExists(config('backup.path'));
        $handle = fopen(config('backup.path').'/.operation.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) {
                fclose($handle);
            }
            throw new RuntimeException('Một thao tác sao lưu hoặc khôi phục đang chạy. Hãy thử lại sau.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function schema(): array
    {
        $schema = [];
        $database = DB::connection()->getDriverName() === 'sqlite' ? 'main' : DB::connection()->getDatabaseName();
        foreach (Schema::getTableListing(schema: $database, schemaQualified: false) as $table) {
            if (! in_array($table, self::EXCLUDED, true)) {
                $schema[$table] = array_map(
                    fn ($column) => ['name' => $column['name'], 'type' => $column['type_name'], 'nullable' => $column['nullable']],
                    Schema::getColumns($table),
                );
            }
        }
        ksort($schema);

        return $schema;
    }

    public function create(string $reason = 'manual'): string
    {
        $schema = $this->schema();
        $tables = DB::transaction(function () use ($schema): array {
            $tables = [];
            foreach ($schema as $table => $columns) {
                $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            }

            return $tables;
        });
        $files = [];
        $total = 0;
        foreach (config('backup.roots') as $root => $path) {
            if (! is_dir($path)) {
                continue;
            }
            foreach (File::allFiles($path) as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Không sao lưu liên kết tệp. Hãy kiểm tra thư mục tài liệu.');
                }
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                if ($relative === '.gitignore') {
                    continue;
                }
                $contents = File::get($file->getPathname());
                $total += strlen($contents);
                if ($total > config('backup.max_bytes')) {
                    throw new RuntimeException('Tệp đính kèm vượt giới hạn sao lưu 128 MB.');
                }
                $files[$root.'/'.$relative] = base64_encode($contents);
            }
        }
        $payload = [
            'format' => 1, 'created_at' => now()->toIso8601String(), 'reason' => $reason,
            'schema' => $schema, 'tables' => $tables, 'files' => $files,
            'legacy' => base64_encode(File::get(config('backup.legacy_path'))),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > config('backup.max_bytes')) {
            throw new RuntimeException('Dữ liệu vượt giới hạn sao lưu 128 MB.');
        }
        $encrypted = Crypt::encryptString($json);
        $compressed = gzencode($encrypted, 6);
        if ($compressed === false) {
            throw new RuntimeException('Không nén được bản sao lưu.');
        }
        $id = (string) Str::uuid();
        File::ensureDirectoryExists(config('backup.path'));
        $this->write($this->path($id).'.tmp', $compressed);
        if (! File::move($this->path($id).'.tmp', $this->path($id))) {
            throw new RuntimeException('Không hoàn tất được tệp sao lưu.');
        }

        return $id;
    }

    public function inspect(string $path): array
    {
        if (! is_file($path) || filesize($path) > config('backup.max_bytes')) {
            throw new RuntimeException('Tệp không tồn tại hoặc vượt quá 128 MB.');
        }
        $compressed = File::get($path);
        $encrypted = @gzdecode($compressed, config('backup.max_bytes') * 2);
        if ($encrypted === false) {
            throw new RuntimeException('Tệp sao lưu không đúng định dạng.');
        }
        try {
            $json = Crypt::decryptString($encrypted);
            if (strlen($json) > config('backup.max_bytes')) {
                throw new RuntimeException('Bản sao lưu vượt giới hạn dữ liệu.');
            }
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Không xác thực được bản sao lưu. Tệp có thể bị thay đổi hoặc được tạo bằng APP_KEY khác.');
        }
        if (($payload['format'] ?? null) !== 1 || ($payload['schema'] ?? null) !== $this->schema()
            || ! is_array($payload['tables'] ?? null) || ! is_array($payload['files'] ?? null)
            || ! is_string($payload['legacy'] ?? null)) {
            throw new RuntimeException('Bản sao lưu không tương thích với cấu trúc dữ liệu hiện tại.');
        }
        if (array_keys($payload['tables']) !== array_keys($payload['schema'])) {
            throw new RuntimeException('Danh sách bảng sao lưu không hợp lệ.');
        }
        foreach ($payload['tables'] as $table => $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException('Dữ liệu bảng không hợp lệ.');
            }
            $columns = array_column($payload['schema'][$table], 'name');
            foreach ($rows as $row) {
                if (! is_array($row) || array_diff(array_keys($row), $columns) !== [] || count($row) !== count($columns)) {
                    throw new RuntimeException('Dữ liệu cột không hợp lệ.');
                }
                foreach ($row as $value) {
                    if (! is_null($value) && ! is_scalar($value)) {
                        throw new RuntimeException('Giá trị dữ liệu không hợp lệ.');
                    }
                }
            }
        }
        if (! collect($payload['tables']['users'] ?? [])->contains(fn ($user) => $user['role'] === 'admin' && $user['status'] === 'active')) {
            throw new RuntimeException('Bản sao lưu phải có ít nhất một quản trị viên đang hoạt động.');
        }
        $total = 0;
        foreach ($payload['files'] as $relative => $encoded) {
            if (! is_string($relative) || ! preg_match('~^(private|public)/[^\\\\:\x00]+$~u', $relative)
                || preg_match('~(^|/)\\.{1,2}(/|$)|//~', $relative)
                || ! is_string($encoded) || base64_decode($encoded, true) === false) {
                throw new RuntimeException('Đường dẫn hoặc nội dung tệp sao lưu không hợp lệ.');
            }
            $total += strlen(base64_decode($encoded, true));
        }
        $legacy = base64_decode($payload['legacy'], true);
        if ($total > config('backup.max_bytes') || $legacy === false || ! is_array(json_decode($legacy, true))) {
            throw new RuntimeException('Tài liệu trong bản sao lưu không hợp lệ.');
        }

        return $payload;
    }

    public function import(string $path): string
    {
        $this->inspect($path);
        $id = (string) Str::uuid();
        File::ensureDirectoryExists(config('backup.path'));
        if (! File::copy($path, $this->path($id))) {
            throw new RuntimeException('Không lưu được tệp sao lưu đã tải lên.');
        }

        return $id;
    }

    public function restore(string $id): array
    {
        $payload = $this->inspect($this->path($id));
        if (DB::table('knowledge_documents')->whereIn('status', ['queued', 'indexing'])->exists()) {
            throw new RuntimeException('Kho tri thức đang xử lý. Hãy chờ lập chỉ mục hoàn tất trước khi khôi phục.');
        }
        $safetyId = $this->create('before-restore');
        $stage = config('backup.path').'/stage-'.Str::uuid();
        File::ensureDirectoryExists($stage);
        $swapped = [];
        $legacyPath = config('backup.legacy_path');
        $originalLegacy = File::get($legacyPath);
        $documents = [];
        $cleanup = true;
        try {
            foreach (config('backup.roots') as $root => $path) {
                File::ensureDirectoryExists($stage.'/'.$root);
            }
            foreach ($payload['files'] as $relative => $encoded) {
                $target = $stage.'/'.$relative;
                File::ensureDirectoryExists(dirname($target));
                $this->write($target, base64_decode($encoded, true));
            }

            Schema::disableForeignKeyConstraints();
            DB::beginTransaction();
            foreach (array_reverse(array_keys($payload['tables'])) as $table) {
                DB::table($table)->delete();
            }
            foreach ($payload['tables'] as $table => $rows) {
                foreach (array_chunk($rows, 25) as $batch) {
                    DB::table($table)->insert($batch);
                }
            }
            // Reject broken references before committing a restored database.
            foreach (array_keys($payload['tables']) as $table) {
                foreach (Schema::getForeignKeys($table) as $foreignKey) {
                    $query = DB::table($table.' as child');
                    foreach ($foreignKey['columns'] as $column) {
                        $query->whereNotNull('child.'.$column);
                    }
                    $query->whereNotExists(function ($query) use ($foreignKey): void {
                        $query->selectRaw('1')->from($foreignKey['foreign_table'].' as parent');
                        foreach ($foreignKey['columns'] as $index => $column) {
                            $query->whereColumn('child.'.$column, 'parent.'.$foreignKey['foreign_columns'][$index]);
                        }
                    });
                    if ($query->exists()) {
                        throw new RuntimeException('Bản sao lưu có dữ liệu tham chiếu không hợp lệ.');
                    }
                }
            }
            foreach (DB::table('knowledge_documents')->get() as $document) {
                $approved = $document->status === 'ready';
                $version = (string) Str::uuid();
                DB::table('knowledge_documents')->where('id', $document->id)->update([
                    'status' => $approved ? 'queued' : 'draft', 'index_version' => $version,
                    'indexed_at' => null, 'error' => null, 'updated_at' => now(),
                ]);
                if ($approved) {
                    $documents[] = [$document->id, $version];
                }
            }
            foreach (config('backup.roots') as $root => $path) {
                if (is_dir($path)) {
                    if (! File::moveDirectory($path, $stage.'/old-'.$root)) {
                        throw new RuntimeException('Không tạo được bản dự phòng thư mục tài liệu.');
                    }
                }
                $swapped[$root] = $path;
                if (! File::moveDirectory($stage.'/'.$root, $path)) {
                    throw new RuntimeException('Không thay thế được thư mục tài liệu.');
                }
            }
            $this->write($legacyPath, base64_decode($payload['legacy'], true));
            DB::table('sessions')->delete();
            DB::table('password_reset_tokens')->delete();
            DB::table('jobs')->where('queue', 'rag')->delete();
            DB::commit();
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach (array_reverse($swapped, true) as $root => $path) {
                if (is_dir($path) && ! File::deleteDirectory($path)) {
                    $cleanup = false;

                    continue;
                }
                if (is_dir($stage.'/old-'.$root)) {
                    if (! File::moveDirectory($stage.'/old-'.$root, $path)) {
                        $cleanup = false;
                    }
                }
            }
            $this->write($legacyPath, $originalLegacy);
            if (! $cleanup) {
                throw new RuntimeException('Khôi phục tệp chưa hoàn tất. Bản gốc được giữ trong vùng dự phòng; hãy kiểm tra quyền ghi trước khi tiếp tục.');
            }
            throw $exception;
        } finally {
            Schema::enableForeignKeyConstraints();
            if ($cleanup) {
                File::deleteDirectory($stage);
            }
        }
        foreach ($documents as [$documentId, $version]) {
            try {
                IndexKnowledgeDocument::dispatch($documentId, $version);
            } catch (Throwable $exception) {
                DB::table('knowledge_documents')->where('id', $documentId)->update([
                    'status' => 'error', 'error' => 'Khôi phục thành công; hãy duyệt lại tài liệu để tạo chỉ mục.',
                ]);
                report($exception);
            }
        }

        return ['safety_id' => $safetyId, 'documents' => count($documents)];
    }
}
