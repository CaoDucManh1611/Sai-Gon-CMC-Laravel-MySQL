<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class RagService
{
    public function collection(): string
    {
        return config('rag.collection').'_'.substr(hash('sha256', config('rag.embedding_model')), 0, 8);
    }

    /** @return list<list<float>> */
    public function embed(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk($texts, 8) as $batch) {
            $response = Http::connectTimeout(5)->timeout(180)->post(
                rtrim(config('rag.ollama_url'), '/').'/api/embed',
                ['model' => config('rag.embedding_model'), 'input' => array_values($batch), 'truncate' => false, 'keep_alive' => '30m'],
            )->throw();
            $embeddings = $response->json('embeddings');
            if (! is_array($embeddings) || count($embeddings) !== count($batch)) {
                throw new RuntimeException('Embedding service returned incomplete data.');
            }
            foreach ($embeddings as $vector) {
                if (! is_array($vector) || ! array_is_list($vector) || $vector === []
                    || count(array_filter($vector, fn ($n) => is_numeric($n) && is_finite((float) $n))) !== count($vector)
                    || array_sum(array_map(fn ($n) => $n * $n, $vector)) <= 0
                    || ($vectors !== [] && count($vector) !== count($vectors[0]))) {
                    throw new RuntimeException('Embedding service returned an invalid vector.');
                }
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    public function prepare(string $markdown): array
    {
        return app(MarkdownChunker::class)->split($markdown, fn (array $texts) => $this->embed($texts));
    }

    public function index(object $document): void
    {
        $chunks = json_decode($document->chunks, true, 512, JSON_THROW_ON_ERROR);
        $vectors = $this->embed(array_map(fn ($chunk) => $document->title."\n".$chunk['title']."\n".$chunk['content'], $chunks));
        $base = rtrim(config('rag.qdrant_url'), '/').'/collections/'.$this->collection();
        $collection = Http::connectTimeout(5)->timeout(15)->get($base);
        if ($collection->status() === 404) {
            $created = Http::connectTimeout(5)->timeout(15)->put($base, [
                'vectors' => ['size' => count($vectors[0]), 'distance' => 'Cosine'],
            ]);
            if (! $created->successful() && $created->status() !== 409) {
                $created->throw();
            }
        } else {
            $collection->throw();
        }

        $points = [];
        foreach ($chunks as $index => $chunk) {
            $points[] = [
                'id' => (string) Str::uuid(),
                'vector' => $vectors[$index],
                'payload' => [
                    'document_id' => $document->id,
                    'version' => $document->index_version,
                    'chunk_index' => $index,
                ],
            ];
        }
        foreach (array_chunk($points, 32) as $batch) {
            Http::connectTimeout(5)->timeout(30)->put($base.'/points?wait=true', ['points' => $batch])->throw();
        }
        Http::connectTimeout(5)->timeout(30)->post($base.'/points/delete?wait=true', [
            'filter' => [
                'must' => [['key' => 'document_id', 'match' => ['value' => $document->id]]],
                'must_not' => [['key' => 'version', 'match' => ['value' => $document->index_version]]],
            ],
        ])->throw();
    }

    /** @return list<array{title: string, section: string, content: string, url: string, score: float}> */
    public function search(string $question): array
    {
        $documents = DB::table('knowledge_documents')->where('status', 'ready')->where('active', true)
            ->where('embedding_model', config('rag.embedding_model'))->get(['id', 'title', 'index_version'])->keyBy('id');
        if ($documents->isEmpty()) {
            return [];
        }

        $cacheKey = 'rag:query-vector:'.hash('sha256', config('rag.ollama_url').'|'.config('rag.embedding_model').'|'.Str::lower(trim($question)));
        $vector = Cache::remember($cacheKey, now()->addMinutes(30), fn () => $this->embed([$question])[0]);
        $response = Http::connectTimeout(5)->timeout(20)->post(
            rtrim(config('rag.qdrant_url'), '/').'/collections/'.$this->collection().'/points/query',
            [
                'query' => $vector,
                'filter' => ['must' => [
                    ['key' => 'document_id', 'match' => ['any' => $documents->keys()->all()]],
                    ['key' => 'version', 'match' => ['any' => $documents->pluck('index_version')->all()]],
                ]],
                'limit' => config('rag.top_k') * 3,
                'score_threshold' => config('rag.min_score'),
                'with_payload' => true,
            ],
        );
        if ($response->status() === 404) {
            throw new RuntimeException('The knowledge vector collection is missing. Reindex documents.');
        }
        $response->throw();
        $matches = [];
        $seen = [];
        $documentChunks = [];
        foreach ($response->json('result.points', []) as $point) {
            $payload = $point['payload'] ?? [];
            $document = $documents->get($payload['document_id'] ?? '');
            if (! $document || ($payload['version'] ?? '') !== $document->index_version
                || ! isset($payload['chunk_index']) || ! is_int($payload['chunk_index'])
                || ($point['score'] ?? 0) < config('rag.min_score')) {
                continue;
            }
            $index = $payload['chunk_index'];
            $chunks = $documentChunks[$document->id] ??= json_decode(
                DB::table('knowledge_documents')->where('id', $document->id)->value('chunks'),
                true, 512, JSON_THROW_ON_ERROR,
            );
            $chunk = $chunks[$index] ?? null;
            $key = $document->id.':'.$index;
            if (! $chunk || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $matches[] = [
                'title' => $document->title,
                'section' => $chunk['title'],
                'content' => $chunk['content'],
                'url' => route('assistant.source', ['id' => $document->id, 'chunk' => $index], false),
                'score' => round((float) $point['score'], 4),
            ];
            if (count($matches) >= config('rag.top_k')) {
                break;
            }
        }

        return $matches;
    }

    public function deleteVectors(string $id): void
    {
        $response = Http::connectTimeout(5)->timeout(20)->post(
            rtrim(config('rag.qdrant_url'), '/').'/collections/'.$this->collection().'/points/delete?wait=true',
            ['filter' => ['must' => [['key' => 'document_id', 'match' => ['value' => $id]]]]],
        );
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    public function health(): array
    {
        $result = [];
        foreach (['embedding' => rtrim(config('rag.ollama_url'), '/').'/api/tags', 'vector' => rtrim(config('rag.qdrant_url'), '/').'/healthz'] as $name => $url) {
            try {
                $response = Http::connectTimeout(1)->timeout(2)->get($url);
                $result[$name] = $response->successful();
                if ($name === 'embedding' && $result[$name]) {
                    $model = config('rag.embedding_model');
                    $result[$name] = collect($response->json('models', []))->contains(
                        fn ($item) => in_array($item['name'] ?? '', [$model, $model.':latest'], true),
                    );
                }
            } catch (\Throwable) {
                $result[$name] = false;
            }
        }

        return $result;
    }
}
