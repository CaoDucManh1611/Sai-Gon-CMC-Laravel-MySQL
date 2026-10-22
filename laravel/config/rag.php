<?php

return [
    'ollama_url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
    'embedding_model' => env('RAG_EMBEDDING_MODEL', 'bge-m3'),
    'qdrant_url' => env('QDRANT_URL', 'http://127.0.0.1:6333'),
    'collection' => env('QDRANT_COLLECTION', 'cmc_knowledge'),
    'top_k' => 5,
    'min_score' => (float) env('RAG_MIN_SCORE', 0.45),
];
