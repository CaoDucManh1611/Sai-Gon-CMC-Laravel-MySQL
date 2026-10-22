<?php

return [
    'path' => storage_path('app/backups'),
    'max_bytes' => 128 * 1024 * 1024,
    'roots' => [
        'private' => storage_path('app/private'),
        'public' => storage_path('app/public'),
    ],
    'legacy_path' => resource_path('ai/chatbot_knowledge.vi.json'),
];
