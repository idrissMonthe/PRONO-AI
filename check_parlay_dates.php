<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$parlays = \App\Models\Parlay::orderByDesc('id')->get(['id','title','date_for','risk_level','created_at']);
foreach ($parlays as $p) {
    echo "ID={$p->id} date_for={$p->date_for} risk={$p->risk_level} created={$p->created_at} title=" . substr($p->title, 0, 40) . "\n";
}

echo "\nToday: " . \Carbon\Carbon::now('Africa/Douala')->toDateString() . "\n";
