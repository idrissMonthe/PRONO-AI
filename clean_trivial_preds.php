<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Effacer les mauvais published_market sur les vieilles prédictions PYTHON_ESTIMATE triviales
$trivialSelections = ['over_0_5', 'under_0_5', 'over_5_5', 'under_5_5', 'under_4_5'];

$updated = \App\Models\Prediction::where('published_market', 'totals')
    ->whereIn('published_selection', $trivialSelections)
    ->where('prediction_origin', 'PYTHON_ESTIMATE')
    ->update([
        'published_market' => null,
        'published_selection' => null,
        'published_probability' => null,
    ]);

echo "Predictions triviales PYTHON_ESTIMATE nettoyées: $updated\n";

// Vérification
$remaining = \App\Models\Prediction::where('published_market', 'totals')
    ->whereIn('published_selection', $trivialSelections)
    ->count();
echo "Restantes après nettoyage: $remaining\n";

// Stats finales
$total = \App\Models\Prediction::where('is_official', 1)->count();
$withPub = \App\Models\Prediction::where('is_official', 1)->whereNotNull('published_market')->count();
echo "Total official: $total | Avec published_market: $withPub\n";
