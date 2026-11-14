<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Voir les titres des parlays en DB
$parlays = \App\Models\Parlay::orderByDesc('id')->take(10)->get();
foreach ($parlays as $p) {
    $title = $p->title ?? $p->name ?? 'N/A';
    $picks = $p->picks ?? $p->selections_json ?? [];
    echo "ID={$p->id} type={$p->parlay_type} title=" . json_encode($title, JSON_UNESCAPED_UNICODE) . "\n";
    if (is_array($picks) && count($picks) > 0) {
        $first = $picks[0];
        $label = $first['label'] ?? $first['selection'] ?? 'N/A';
        echo "  -> première sélection: " . json_encode($label, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// Vérifier le charset de la table parlays
$charset = \Illuminate\Support\Facades\DB::select("SHOW CREATE TABLE parlays");
if ($charset) {
    echo "\nCREATE TABLE:\n" . $charset[0]->{'Create Table'} . "\n";
}
