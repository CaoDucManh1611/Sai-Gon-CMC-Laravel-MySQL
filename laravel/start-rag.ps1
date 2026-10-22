param()
$ErrorActionPreference = 'Stop'
Push-Location $PSScriptRoot
try {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        $phpExecutable = $phpCommand.Source
    } else {
        $phpDirectory = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\..\bin\php'))
        $phpExecutable = Get-ChildItem -LiteralPath $phpDirectory -Filter php.exe -Recurse | Select-Object -First 1 -ExpandProperty FullName
    }
    if (!$phpExecutable) { throw 'Không tìm thấy PHP. Hãy thêm PHP Laragon vào PATH.' }
    docker info --format '{{.ServerVersion}}'
    if ($LASTEXITCODE -ne 0) { throw 'Hãy mở Docker Desktop trước khi chạy RAG.' }
    docker compose -f compose.rag.yaml up -d
    if ($LASTEXITCODE -ne 0) { throw 'Không khởi động được dịch vụ RAG.' }
    docker compose -f compose.rag.yaml exec -T ollama ollama show bge-m3 *> $null
    if ($LASTEXITCODE -ne 0) {
        docker compose -f compose.rag.yaml exec -T ollama ollama pull bge-m3
        if ($LASTEXITCODE -ne 0) { throw 'Không tải được mô hình embedding BGE-M3.' }
    }
    & $phpExecutable artisan migrate --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Không cập nhật được cơ sở dữ liệu.' }
    & $phpExecutable artisan rag:import-legacy --index --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Không nhập được tri thức hiện có.' }
    Write-Host 'Admin: http://127.0.0.1:8000/management/knowledge/documents'
    Write-Host 'Worker RAG đang chạy. Giữ terminal này để tiếp tục xử lý tài liệu; Ctrl+C để dừng worker.'
    & $phpExecutable artisan queue:work rag --queue=rag --tries=1 --timeout=1800 --sleep=2 --no-interaction
} finally {
    Pop-Location
}
