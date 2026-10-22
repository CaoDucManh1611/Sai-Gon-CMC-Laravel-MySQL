<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    public function index(): View
    {
        $knowledge = $this->readKnowledge();
        $chunks = old('chunks');
        if (! is_array($chunks)) {
            $chunks = $knowledge['chunks'];
        }

        foreach ($chunks as &$chunk) {
            if (! is_array($chunk)) {
                $chunk = [];
            }
            $chunk['sources'] = is_array($chunk['sources'] ?? null)
                ? implode("\n", $chunk['sources'])
                : ($chunk['sources'] ?? '');
        }
        unset($chunk);

        return view('staff.knowledge', ['chunks' => $chunks]);
    }

    public function update(Request $request): RedirectResponse
    {
        $chunks = $request->input('chunks');

        if (is_array($chunks)) {
            foreach ($chunks as $index => $chunk) {
                if (is_array($chunk) && is_string($chunk['sources'] ?? null)) {
                    $sources = preg_split('/\R/u', $chunk['sources']) ?: [];
                    $chunks[$index]['sources'] = array_values(array_filter(
                        array_map('trim', $sources),
                        fn (string $source): bool => $source !== '',
                    ));
                }
            }
            $request->merge(['chunks' => $chunks]);
        }

        $data = $request->validate([
            'chunks' => ['required', 'array', 'min:1'],
            'chunks.*' => ['required', 'array:id,topic,title,content,sources'],
            'chunks.*.id' => ['required', 'string', 'max:120', 'distinct'],
            'chunks.*.topic' => ['required', 'string', 'max:100'],
            'chunks.*.title' => ['required', 'string', 'max:200'],
            'chunks.*.content' => ['required', 'string', 'max:10000'],
            'chunks.*.sources' => ['required', 'array', 'min:1'],
            'chunks.*.sources.*' => ['required', 'string', 'max:500'],
        ]);

        $knowledge = $this->readKnowledge();
        $knowledge['chunks'] = array_values($data['chunks']);
        $path = $this->knowledgePath();
        $temporaryPath = tempnam(dirname($path), '.knowledge-');
        $contents = json_encode(
            $knowledge,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;

        if ($temporaryPath === false || file_put_contents($temporaryPath, $contents, LOCK_EX) === false || ! rename($temporaryPath, $path)) {
            if (is_string($temporaryPath) && file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }

            abort(500, 'Không thể lưu kho tri thức.');
        }

        return back()->with('success', 'Đã cập nhật kho tri thức cho trợ lý.');
    }

    /**
     * @return array{schema_version: int, locale: string, audience: string, chunks: array}
     */
    private function readKnowledge(): array
    {
        $contents = file_get_contents($this->knowledgePath());
        abort_if($contents === false, 500, 'Không thể đọc kho tri thức.');

        $knowledge = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(
            is_array($knowledge)
            && ($knowledge['schema_version'] ?? null) === 1
            && ($knowledge['locale'] ?? null) === 'vi'
            && ($knowledge['audience'] ?? null) === 'customer'
            && is_array($knowledge['chunks'] ?? null)
            && array_is_list($knowledge['chunks'])
            && $knowledge['chunks'] !== [],
            500,
            'Kho tri thức không đúng định dạng.',
        );

        return $knowledge;
    }

    private function knowledgePath(): string
    {
        return resource_path('ai/chatbot_knowledge.vi.json');
    }
}
