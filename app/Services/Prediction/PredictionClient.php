<?php

namespace App\Services\Prediction;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PredictionClient
{
    protected string $baseUrl;
    protected ?string $secret;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.ai_service.url', env('AI_SERVICE_URL', 'http://127.0.0.1:8001'));
        $this->secret = config('services.ai_service.secret');
        $this->timeout = 5;
    }

    public function checkHealth(): bool
    {
        try {
            $resp = Http::timeout(2)->get("{$this->baseUrl}/v1/health");
            return $resp->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    public function modelReadiness(): ?array
    {
        try {
            $response = Http::timeout(2)->get("{$this->baseUrl}/v1/ready");

            return $response->successful() ? $response->json() : null;
        } catch (Exception $e) {
            return null;
        }
    }

    public function predict(array $payload): array
    {
        if (empty($this->secret)) {
            return $this->deterministicFallbackPredict($payload);
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['X-Service-Secret' => $this->secret])
                ->post("{$this->baseUrl}/v1/predict", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('AI Service predict non-200 response', ['status' => $response->status(), 'body' => $response->body()]);
        } catch (Exception $e) {
            Log::warning('AI Service connection error, applying deterministic fallback: ' . $e->getMessage());
        }

        return $this->deterministicFallbackPredict($payload);
    }

    public function devig(array $odds, string $method = 'shin'): array
    {
        try {
            if (empty($this->secret)) {
                throw new RuntimeException('AI_SERVICE_SECRET is not configured.');
            }

            $response = Http::timeout($this->timeout)
                ->withHeaders(['X-Service-Secret' => $this->secret])
                ->post("{$this->baseUrl}/v1/odds/devig", [
                    'odds' => $odds,
                    'method' => $method,
                ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Exception $e) {
            Log::warning('AI Service devig error: ' . $e->getMessage());
        }

        // Local fallback: Proportional margin removal
        $invSum = array_sum(array_map(fn($o) => 1.0 / max(1.01, $o), $odds));
        $probs = array_map(fn($o) => round((1.0 / max(1.01, $o)) / $invSum, 4), $odds);
        return [
            'method' => 'proportional_fallback',
            'input_odds' => $odds,
            'overround' => round($invSum - 1.0, 4),
            'true_probabilities' => $probs,
            'fair_odds' => array_map(fn($p) => $p > 0 ? round(1.0 / $p, 3) : 999.0, $probs),
            'param' => [],
        ];
    }

    public function evaluate(array $items): array
    {
        if (empty($this->secret)) {
            throw new RuntimeException('AI_SERVICE_SECRET is not configured.');
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Service-Secret' => $this->secret])
                ->post("{$this->baseUrl}/v1/evaluate", ['items' => $items]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Exception $e) {
            Log::error('Evaluation API failed: ' . $e->getMessage());
        }

        throw new RuntimeException('Le service d’évaluation est indisponible.');
    }

    protected function deterministicFallbackPredict(array $payload): array
    {
        $lambdaHome = min(8.0, max(0.1, (float) ($payload['lambda_home_override'] ?? 1.45)));
        $lambdaAway = min(8.0, max(0.1, (float) ($payload['lambda_away_override'] ?? 1.10)));
        $homeScores = $this->poissonProbabilities($lambdaHome);
        $awayScores = $this->poissonProbabilities($lambdaAway);
        $matrix = [];
        $matrixMass = 0.0;
        $homeWinMass = 0.0;
        $drawMass = 0.0;
        $awayWinMass = 0.0;
        $bttsMass = 0.0;
        $cleanSheetHomeMass = 0.0;
        $cleanSheetAwayMass = 0.0;
        $totalGoalsMass = [];
        $scoreRows = [];

        foreach ($homeScores as $homeGoals => $homeProbability) {
            $row = [];
            foreach ($awayScores as $awayGoals => $awayProbability) {
                $probability = $homeProbability * $awayProbability;
                $row[] = $probability;
                $matrixMass += $probability;
                $totalGoals = $homeGoals + $awayGoals;
                $totalGoalsMass[$totalGoals] = ($totalGoalsMass[$totalGoals] ?? 0.0) + $probability;

                if ($homeGoals > $awayGoals) {
                    $homeWinMass += $probability;
                } elseif ($homeGoals === $awayGoals) {
                    $drawMass += $probability;
                } else {
                    $awayWinMass += $probability;
                }

                if ($homeGoals > 0 && $awayGoals > 0) {
                    $bttsMass += $probability;
                }
                if ($awayGoals === 0) {
                    $cleanSheetHomeMass += $probability;
                }
                if ($homeGoals === 0) {
                    $cleanSheetAwayMass += $probability;
                }
                if ($homeGoals <= 10 && $awayGoals <= 10) {
                    $scoreRows[] = [
                        'score' => $homeGoals . '-' . $awayGoals,
                        'p' => $probability,
                    ];
                }
            }
            $matrix[] = $row;
        }

        $matrixMass = max($matrixMass, 1e-12);
        foreach ($totalGoalsMass as $totalGoals => $probability) {
            $totalGoalsMass[$totalGoals] = $probability / $matrixMass;
        }
        usort($scoreRows, fn (array $left, array $right) => $right['p'] <=> $left['p']);
        $topScores = array_map(
            fn (array $score) => ['score' => $score['score'], 'p' => round($score['p'] / $matrixMass, 4)],
            array_slice($scoreRows, 0, 10)
        );
        $totals = [];
        foreach ([0.5, 1.5, 2.5, 3.5, 4.5] as $threshold) {
            $over = 0.0;
            foreach ($totalGoalsMass as $goals => $probability) {
                if ($goals > $threshold) {
                    $over += $probability;
                }
            }
            $key = str_replace('.', '_', (string) $threshold);
            $totals['over_' . $key] = round($over, 4);
            $totals['under_' . $key] = round(1.0 - $over, 4);
        }

        $probabilityHome = $homeWinMass / $matrixMass;
        $probabilityDraw = $drawMass / $matrixMass;
        $probabilityAway = $awayWinMass / $matrixMass;
        $warnings = ['prediction_service_unavailable', 'untrained_poisson_fallback', 'analysis_abstained'];
        if (!isset($payload['lambda_home_override'], $payload['lambda_away_override'])) {
            $warnings[] = 'default_lambda_assumptions';
        }

        return [
            'match_id' => $payload['match_id'] ?? 'sim-1',
            'model' => ['version' => 'UNTRAINED_POISSON_FALLBACK', 'family' => 'pure'],
            'computed_at' => now()->utc()->toIso8601String(),
            'data_snapshot_hash' => hash('sha256', json_encode($payload)),
            'expected_goals' => [
                'home' => $lambdaHome,
                'away' => $lambdaAway,
                'total' => round($lambdaHome + $lambdaAway, 2),
            ],
            'probabilities' => [
                'home' => ['p' => round($probabilityHome, 4), 'ci80' => [0.0, 1.0], 'ci95' => [0.0, 1.0]],
                'draw' => ['p' => round($probabilityDraw, 4), 'ci80' => [0.0, 1.0], 'ci95' => [0.0, 1.0]],
                'away' => ['p' => round($probabilityAway, 4), 'ci80' => [0.0, 1.0], 'ci95' => [0.0, 1.0]],
            ],
            'markets' => [
                '1N2' => [
                    'home' => round($probabilityHome, 4),
                    'draw' => round($probabilityDraw, 4),
                    'away' => round($probabilityAway, 4),
                ],
                'double_chance' => [
                    '1X' => round($probabilityHome + $probabilityDraw, 4),
                    'X2' => round($probabilityDraw + $probabilityAway, 4),
                    '12' => round($probabilityHome + $probabilityAway, 4),
                ],
                'totals' => $totals,
                'btts' => [
                    'yes' => round($bttsMass / $matrixMass, 4),
                    'no' => round(1.0 - $bttsMass / $matrixMass, 4),
                ],
                'clean_sheet' => [
                    'home' => round($cleanSheetHomeMass / $matrixMass, 4),
                    'away' => round($cleanSheetAwayMass / $matrixMass, 4),
                ],
            ],
            'top_scores' => $topScores,
            'matrix' => [
                'max_goals' => max(count($homeScores), count($awayScores)) - 1,
                'truncated_mass' => round(max(0.0, 1.0 - $matrixMass), 8),
            ],
            'quality' => ['score' => 0.0, 'tier' => 'insufficient', 'confidence_tier' => 'low'],
            'confidence_tier' => 'low',
            'abstain' => true,
            'warnings' => $warnings,
            'duration_ms' => 1,
        ];
    }

    protected function poissonProbabilities(float $lambda): array
    {
        $probabilities = [exp(-$lambda)];
        $cumulative = $probabilities[0];

        while (1.0 - $cumulative > 1e-8 && count($probabilities) < 100) {
            $goals = count($probabilities);
            $nextProbability = $probabilities[$goals - 1] * $lambda / $goals;
            $probabilities[] = $nextProbability;
            $cumulative += $nextProbability;
        }

        return $probabilities;
    }
}

