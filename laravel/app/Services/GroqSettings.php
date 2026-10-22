<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class GroqSettings
{
    /** @return array{key: ?string, model: string} */
    public function credentials(): array
    {
        $settings = DB::table('ai_settings')->where('id', 1)->first();
        if (! $settings) {
            return ['key' => config('services.groq.key'), 'model' => config('services.groq.model')];
        }
        try {
            $key = $settings->api_key ? Crypt::decryptString($settings->api_key) : null;
        } catch (DecryptException) {
            $key = null;
        }

        return ['key' => $key, 'model' => $settings->model];
    }

    public function save(?string $key, string $model): void
    {
        DB::table('ai_settings')->updateOrInsert(['id' => 1], [
            'api_key' => $key !== null && $key !== '' ? Crypt::encryptString($key) : null,
            'model' => $model, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
