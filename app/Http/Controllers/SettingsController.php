<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditEvent;
use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\Integrity\HashChainService;
use App\Services\Prediction\PredictionClient;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(
        HashChainService $hashService,
        PredictionClient $predictionClient,
        GeminiExplanationService $geminiService
    ) {
        $settings = AppSetting::all()->pluck('value_json', 'key')->toArray();

        // Integrity ledger test
        $integrity = $hashService->verifyLedgerIntegrity();

        // System health checks (CDC 14.3 & 15.2)
        $aiServiceOnline = $predictionClient->checkHealth();
        $aiModelLoaded = (bool) ($predictionClient->modelReadiness()['model_loaded'] ?? false);
        $geminiConfigured = $geminiService->isConfigured();

        // Provider configs (presence only, no secret displayed per CDC 15.2)
        $providers = [
            'football_data_org' => [
                'name' => 'football-data.org',
                'configured' => !empty(config('services.football_data.api_key')),
                'status' => !empty(config('services.football_data.api_key')) ? 'Clé présente · connexion à tester' : 'Non configuré',
            ],
            'the_odds_api' => [
                'name' => 'The Odds API',
                'configured' => !empty(config('services.the_odds_api.api_key')),
                'status' => !empty(config('services.the_odds_api.api_key')) ? 'Clé présente · connexion à tester' : 'Non configuré',
            ],
            'google_gemini' => [
                'name' => 'Google Gemini (Assistant IA)',
                'configured' => $geminiConfigured,
                'status' => $geminiConfigured ? 'Clé présente (' . config('services.gemini.model', 'gemini-2.5-flash') . ')' : 'Non configuré (repli déterministe)',
            ],
            'ai_service_local' => [
                'name' => 'FastAPI Moteur IA Local (127.0.0.1:8001)',
                'configured' => $aiServiceOnline,
                'status' => !$aiServiceOnline
                    ? 'Hors ligne'
                    : ($aiModelLoaded ? 'Connecté · modèle chargé' : 'Connecté · modèle non entraîné, abstention active'),
            ],
        ];

        // Audit Trail (CDC 16.1 & 15.8)
        $auditLogs = AuditEvent::orderBy('occurred_at_utc', 'desc')->take(10)->get();

        return view('settings.index', compact(
            'settings',
            'integrity',
            'aiServiceOnline',
            'aiModelLoaded',
            'geminiConfigured',
            'providers',
            'auditLogs'
        ));
    }

    public function update(Request $request)
    {
        $fields = [
            'display_odds_format',
            'active_model_family',
            'time_decay_half_life_days',
            'freeze_time_minutes_before_kickoff',
            'data_quality_min_score',
            'min_edge_alert_pct',
            'devig_method',
            'analysis_only_mode',
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $val = $request->input($field);
                if ($field === 'analysis_only_mode') {
                    $val = (bool)$val;
                } elseif (is_numeric($val)) {
                    $val = (float)$val;
                }
                AppSetting::set($field, $val);
            }
        }

        AuditEvent::log('SETTINGS_UPDATED', [
            'updated_by' => 'analyste_local',
            'timestamp' => now()->utc()->toIso8601String(),
        ]);

        return redirect()->route('settings.index')->with('success', 'Paramètres mis à jour et consignés dans le journal d\'audit.');
    }

    public function verifyLedger(HashChainService $hashService)
    {
        $res = $hashService->verifyLedgerIntegrity();
        return redirect()->route('settings.index')->with(
            $res['status'] === 'valid' ? 'success' : 'error',
            $res['message']
        );
    }
}

