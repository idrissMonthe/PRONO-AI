<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperBetMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_unmeasured_paper_bet_metrics_are_displayed_as_unavailable(): void
    {
        $this->get('/odds')
            ->assertOk()
            ->assertSee('Indicateurs de suivi avancés', false)
            ->assertSee('Résultat des simulations', false)
            ->assertSee('>—</div>', false);
    }
}
