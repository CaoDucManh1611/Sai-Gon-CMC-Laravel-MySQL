<?php

namespace App\Services;

use InvalidArgumentException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;

class MarkdownChunker
{
    /** @return list<array{title: string, content: string, line: int}> */
    public function split(string $markdown, ?callable $embed = null): array
    {
        if (! mb_check_encoding($markdown, 'UTF-8') || str_contains($markdown, "\0")) {
            throw new InvalidArgumentException('Tài liệu phải là Markdown UTF-8 hợp lệ.');
        }
        $markdown = str_starts_with($markdown, "\xEF\xBB\xBF") ? substr($markdown, 3) : $markdown;
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $environment = Environment::createCommonMarkEnvironment(['max_nesting_level' => 50]);
        $environment->addExtension(new TableExtension);
        $document = (new MarkdownParser($environment))->parse(implode("\n", $lines));
        $sections = [];
        $headings = [];
        $blocks = [];
        $flush = function () use (&$sections, &$headings, &$blocks): void {
            if ($blocks !== []) {
                $title = implode(' / ', $headings) ?: 'Nội dung';
                if (mb_strlen($title) > 500) {
                    throw new InvalidArgumentException('Đường dẫn đề mục quá dài. Hãy rút gọn tiêu đề trong tài liệu.');
                }
                $sections[] = ['title' => $title, 'blocks' => $blocks];
                $blocks = [];
            }
        };

        foreach ($document->children() as $node) {
            if ($node instanceof Heading) {
                $flush();
                $headings = array_filter($headings, fn ($key) => $key < $node->getLevel(), ARRAY_FILTER_USE_KEY);
                $title = '';
                $walker = $node->walker();
                while ($event = $walker->next()) {
                    if ($event->isEntering() && $event->getNode() instanceof StringContainerInterface) {
                        $title .= $event->getNode()->getLiteral();
                    }
                }
                $headings[$node->getLevel()] = trim($title);

                continue;
            }
            $start = $node->getStartLine();
            $end = $node->getEndLine();
            $text = trim(implode("\n", array_slice($lines, $start - 1, $end - $start + 1)));
            if ($text !== '') {
                $blocks[] = ['content' => $text, 'line' => $start, 'paragraph' => $node instanceof Paragraph];
            }
        }
        $flush();

        $chunks = [];
        foreach ($sections as $section) {
            $chunks = array_merge($chunks, $this->divideSection($section, $embed));
            if (count($chunks) > 200) {
                throw new InvalidArgumentException('Tài liệu vượt quá 200 phần. Hãy chia thành các tài liệu nhỏ hơn.');
            }
        }
        if ($chunks === []) {
            throw new InvalidArgumentException('Tài liệu phải có nội dung, không chỉ chứa tiêu đề.');
        }

        return $chunks;
    }

    private function divideSection(array $section, ?callable $embed): array
    {
        $text = implode("\n\n", array_column($section['blocks'], 'content'));
        if (mb_strlen($text) <= 4000) {
            return [['title' => $section['title'], 'content' => $text, 'line' => $section['blocks'][0]['line']]];
        }

        $blocks = [];
        foreach ($section['blocks'] as $block) {
            if ($block['paragraph'] && mb_strlen($block['content']) > 4000) {
                foreach (preg_split('/(?<=[.!?。！？])\\s+/u', $block['content'], -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
                    $blocks[] = ['content' => $sentence, 'line' => $block['line']];
                }
            } else {
                $blocks[] = $block;
            }
        }
        foreach ($blocks as $block) {
            if (mb_strlen($block['content']) > 12000) {
                throw new InvalidArgumentException('Có bảng, danh sách hoặc đoạn quá dài. Hãy bổ sung đề mục hoặc tách đoạn trong tài liệu rồi tải lại.');
            }
        }
        if (count($blocks) > 500) {
            throw new InvalidArgumentException('Một đề mục có quá nhiều đoạn. Hãy chia thành các đề mục nhỏ hơn.');
        }
        $vectors = $embed !== null && count($blocks) > 1 ? $embed(array_column($blocks, 'content')) : [];
        $result = [];
        $current = '';
        $start = $blocks[0]['line'];
        foreach ($blocks as $index => $block) {
            $changesTopic = isset($vectors[$index], $vectors[$index - 1])
                && $this->cosine($vectors[$index - 1], $vectors[$index]) < 0.65;
            if ($current !== '' && ((mb_strlen($current) >= 600 && $changesTopic) || mb_strlen($current."\n\n".$block['content']) > 6000)) {
                $result[] = ['title' => $section['title'], 'content' => $current, 'line' => $start];
                $current = '';
            }
            if ($current === '') {
                $start = $block['line'];
            }
            $current .= ($current === '' ? '' : "\n\n").$block['content'];
        }
        if ($current !== '') {
            $result[] = ['title' => $section['title'], 'content' => $current, 'line' => $start];
        }

        return $result;
    }

    private function cosine(array $left, array $right): float
    {
        $dot = $a = $b = 0.0;
        foreach ($left as $index => $value) {
            $dot += $value * $right[$index];
            $a += $value * $value;
            $b += $right[$index] * $right[$index];
        }

        return $a > 0 && $b > 0 ? $dot / sqrt($a * $b) : 0;
    }
}
