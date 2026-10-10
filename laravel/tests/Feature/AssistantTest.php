<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_uses_configured_gemini_without_exposing_key(): void
    {
        $this->seed();
        config(['services.gemini.key' => 'test-secret', 'services.gemini.model' => 'gemini-2.5-flash']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Hãy xem xi măng PCB40.']]]]],
        ])]);
        $response = $this->post('/assistant', ['question' => 'Nên chọn xi măng nào cho công trình?']);
        $response->assertOk()->assertSee('Hãy xem xi măng PCB40.')->assertDontSee('test-secret');
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-secret')
            && str_contains($request->url(), 'gemini-2.5-flash:generateContent'));
    }
}
