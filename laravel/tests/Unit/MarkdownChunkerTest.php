<?php

namespace Tests\Unit;

use App\Services\MarkdownChunker;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MarkdownChunkerTest extends TestCase
{
    public function test_preserves_heading_context_tables_and_code_without_treating_code_as_headings(): void
    {
        $markdown = "# Chính sách\n\n## Đổi trả\n\nĐiều kiện đổi trả.\n\n| Mặt hàng | Điều kiện |\n| --- | --- |\n| Xi măng | Chưa mở |\n\n```md\n# Không phải đề mục\n```\n\n## Thanh toán\n\nThanh toán tại quầy.";
        $chunks = (new MarkdownChunker)->split($markdown);

        $this->assertCount(2, $chunks);
        $this->assertSame('Chính sách / Đổi trả', $chunks[0]['title']);
        $this->assertSame(5, $chunks[0]['line']);
        $this->assertStringContainsString('| Xi măng | Chưa mở |', $chunks[0]['content']);
        $this->assertStringContainsString('# Không phải đề mục', $chunks[0]['content']);
        $this->assertSame('Chính sách / Thanh toán', $chunks[1]['title']);
    }

    public function test_splits_long_sections_at_semantic_changes_instead_of_fixed_lengths(): void
    {
        $first = str_repeat('Quy trình giao hàng. ', 90);
        $second = str_repeat('Theo dõi vận chuyển. ', 90);
        $third = str_repeat('Điều kiện đổi trả. ', 90);
        $calls = 0;
        $chunks = (new MarkdownChunker)->split("# Hướng dẫn\n\n".$first."\n\n".$second."\n\n".$third,
            function (array $texts) use (&$calls): array {
                $calls++;
                $this->assertCount(3, $texts);

                return [[1.0, 0.0], [1.0, 0.0], [0.0, 1.0]];
            });
        $this->assertSame(1, $calls);
        $this->assertCount(2, $chunks);
        $this->assertSame(trim($first)."\n\n".trim($second), $chunks[0]['content']);
        $this->assertSame(trim($third), $chunks[1]['content']);
    }

    public function test_preserves_large_table_as_one_atomic_block(): void
    {
        $table = "| Vật liệu | Quy cách |\n| --- | --- |\n".str_repeat("| Xi măng | PCB40 |\n", 240);
        $chunks = (new MarkdownChunker)->split("# Danh mục\n\n".$table);
        $this->assertCount(1, $chunks);
        $this->assertSame(trim($table), $chunks[0]['content']);
    }

    public function test_rejects_binary_and_empty_markdown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MarkdownChunker)->split("không hợp lệ\0");
    }

    public function test_rejects_document_with_headings_but_no_content(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MarkdownChunker)->split("# Tiêu đề\n\n## Mục trống");
    }
}
