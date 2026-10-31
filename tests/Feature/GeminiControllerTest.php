<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleMatchesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeminiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(SampleMatchesSeeder::class);
    }

    public function test_gemini_status_endpoint(): void
    {
        $response = $this->getJson('/api/gemini/status');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'provider' => 'Google Gemini',
            ]);
    }

    public function test_gemini_test_connection_endpoint(): void
    {
        $response = $this->postJson('/api/gemini/test');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'configured',
                'provider',
                'sample_response',
            ]);
    }

    public function test_gemini_explain_with_match(): void
    {
        $match = \App\Models\FootballMatch::first();

        $response = $this->postJson('/api/gemini/explain', [
            'match_id' => $match->id,
            'mode' => 'beginner',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'mode',
                'provider',
                'text',
                'verified',
                'context',
            ]);
    }

    public function test_gemini_ask_endpoint(): void
    {
        $response = $this->postJson('/api/gemini/ask', [
            'question' => 'Quelle equipe a la meilleure attaque ?',
            'mode' => 'beginner',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'question',
                'provider',
                'text',
            ]);
    }

    public function test_gemini_history_endpoint(): void
    {
        $response = $this->getJson('/api/gemini/history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'count',
                'interactions',
            ]);
    }
}
