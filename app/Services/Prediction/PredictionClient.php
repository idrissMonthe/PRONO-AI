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
        // Le dashboard appelle cette méthode à chaque page : un cache court
        // évite d'ajouter jusqu'à 2 s de latence quand le service est lent.
        return \Illuminate\Support\Facades\Cache::remember('ai-service.readiness', now()->addSeconds(60), function (): ?array {
            try {
                $response = Http::timeout(2)->get("{$this->baseUrl}/v1/ready");

                return $response->successful() ? $response->json() : null;
            } catch (Exception $e) {
                return null;
            }
        });
    }

    public function predict(array $payload): array
    {
        if (empty($this->secret)) {
            throw new RuntimeException('AI_SERVICE_SECRET is not configured; Python model prediction cannot run.');
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['X-Service-Secret' => $this->secret])
                ->post("{$this->baseUrl}/v1/predict", $payload);

            if ($response->successful()) {
                $result = $response->json();
                if (is_array($result)) {
                    return $result;
                }

                throw new RuntimeException('AI Service returned an invalid prediction payload.');
            }

            Log::warning('AI Service predict non-200 response', ['status' => $response->status(), 'body' => $response->body()]);
        } catch (Exception $e) {
            Log::warning('AI Service prediction failed: '.$e->getMessage());

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException('AI Service unavailable; Python model prediction could not run.', 0, $e);
        }

        throw new RuntimeException('AI Service prediction failed with HTTP '.$response->status().'.');
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

    public function train(array $matches): array
    {
        if (empty($this->secret)) {
            throw new RuntimeException('AI_SERVICE_SECRET is not configured.');
        }

        try {
            $response = Http::timeout(300)
                ->withHeaders(['X-Service-Secret' => $this->secret])
                ->post("{$this->baseUrl}/v1/train", ['matches' => $matches]);

            if ($response->successful()) return $response->json();

            // Un 422 vient de la validation Pydantic : sans le détail, la
            // cause réelle (champ hors bornes) reste invisible.
            Log::error('AI Service training rejected the payload.', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 2000),
            ]);
        } catch (Exception $e) {
            Log::error('AI Service training failed: '.$e->getMessage());
        }

        throw new RuntimeException('Le service d’entraînement est indisponible.');
    }
}
