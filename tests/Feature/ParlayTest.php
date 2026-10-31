<?php

namespace Tests\Feature;

use App\Models\Parlay;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleMatchesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Analyse du risque du combiné probabiliste.']]]]],
            ]),
        ]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(SampleMatchesSeeder::class);
    }

    public function test_parlays_page_loads_and_generates_combinations(): void
    {
        $response = $this->get('/parlays');
        $response->assertStatus(200);
        $response->assertSee('Combinés Quotidiens');
        $response->assertSee('PROBABILISTE');

        // Doit avoir généré au moins un combiné en base
        $this->assertGreaterThan(0, Parlay::count());
    }

    public function test_parlay_regenerate_endpoint(): void
    {
        $response = $this->withoutMiddleware()->post('/parlays/generate');
        $response->assertRedirect('/parlays?date=' . now()->toDateString());
        $response->assertSessionHas('success');

        $parlay = Parlay::first();
        $this->assertNotNull($parlay);
        $this->assertNotNull($parlay->combined_odds);
        $this->assertNotNull($parlay->combined_prob);
        $this->assertNotNull($parlay->risk_score);
        $this->assertIsArray($parlay->selections_json);
    }
}
