<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$preds = \App\Models\Prediction::whereNotNull('published_market')
    ->where('is_official', 1)
    ->take(15)
    ->get();

echo "=== PREDICTIONS PUBLIEES ===\n";
foreach ($preds as $p) {
    echo sprintf("ID=%d market=%s selection=%s prob=%.2f origin=%s\n",
        $p->id,
        $p->published_market ?? 'null',
        $p->published_selection ?? 'null',
        $p->published_probability ?? 0,
        $p->prediction_origin ?? 'null'
    );
}

echo "\n=== TOTAL SANS published_market (fallback au buildDailyPicks) ===\n";
$noPublished = \App\Models\Prediction::where('is_official', 1)->whereNull('published_market')->count();
$withPublished = \App\Models\Prediction::where('is_official', 1)->whereNotNull('published_market')->count();
echo "Avec published_market: $withPublished\n";
echo "Sans published_market: $noPublished\n";
