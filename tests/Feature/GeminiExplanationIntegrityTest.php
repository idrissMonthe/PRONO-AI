<?php

namespace Tests\Feature;

use App\Services\AIExplanation\GeminiExplanationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiExplanationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gemini.api_key', 'test-gemini-key');
        config()->set('services.gemini.model', 'test-model');
    }

    public function test_unsupported_numbers_are_replaced_by_the_deterministic_explanation(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'La victoire à domicile est estimée à 99%.']]],
                ]],
            ]),
        ]);

        $result = app(GeminiExplanationService::class)->explainMatch($this->context());

        $this->assertSame('google_gemini', $result['provider']);
        $this->assertTrue($result['is_fallback']);
        $this->assertStringNotContainsString('99%', $result['text']);
        $this->assertDatabaseHas('llm_interactions', ['verification_passed' => false]);
    }

    public function test_numbers_supported_by_the_context_are_kept(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'La probabilité domicile est de 50%, pour 1.4 but attendu.']]],
                ]],
            ]),
        ]);

        $result = app(GeminiExplanationService::class)->explainMatch($this->context());

        $this->assertSame('google_gemini', $result['provider']);
        $this->assertFalse($result['is_fallback']);
        $this->assertDatabaseHas('llm_interactions', ['verification_passed' => true]);
        Http::assertSent(fn ($request) => str_contains($request->url(), ':generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key')
            && ! str_contains($request->url(), 'test-gemini-key'));
    }

    private function context(): array
    {
        return [
            'home_team' => 'Paris FC',
            'away_team' => 'Lyon',
            'expected_goals' => ['home' => 1.4, 'away' => 1.1],
            'probabilities' => [
                'home' => ['p' => 0.5],
                'draw' => ['p' => 0.3],
                'away' => ['p' => 0.2],
            ],
            'top_scores' => [['score' => '1-0', 'p' => 0.2]],
            'quality' => ['score' => 90],
        ];
    }
}
