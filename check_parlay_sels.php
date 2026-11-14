<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Regarde les sélections des 3 derniers parlays
$parlays = \App\Models\Parlay::orderByDesc('id')->take(6)->get();
foreach ($parlays as $p) {
    echo "=== Parlay ID={$p->id} - {$p->title} ===\n";
    $sels = $p->selections_json ?? [];
    foreach (array_slice($sels, 0, 2) as $s) {
        echo "  match_label: " . json_encode($s['match_label'] ?? 'MISSING', JSON_UNESCAPED_UNICODE) . "\n";
        echo "  selection_label: " . json_encode($s['selection_label'] ?? 'MISSING', JSON_UNESCAPED_UNICODE) . "\n";
        echo "  odds: " . ($s['odds'] ?? 'N/A') . "\n";
    }
    echo "\n";
}

// Vérifier le Parlay model - sélections_json est-il casté ?
$model = new \App\Models\Parlay();
echo "Casts: " . json_encode($model->getCasts(), JSON_UNESCAPED_UNICODE) . "\n";
