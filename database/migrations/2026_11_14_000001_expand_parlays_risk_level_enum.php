<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `risk_level` était limité à trois valeurs alors qu'un chemin de génération
 * enregistre des combinés élargis. Sans cette valeur, l'insertion échoue
 * (`Data truncated for column 'risk_level'`) et la page renvoie une erreur 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `parlays` MODIFY `risk_level` ENUM('MODERE','EQUILIBRE','AUDACIEUX','GROS_COMBINE') NOT NULL DEFAULT 'MODERE'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('parlays')->where('risk_level', 'GROS_COMBINE')->update(['risk_level' => 'AUDACIEUX']);

        DB::statement("ALTER TABLE `parlays` MODIFY `risk_level` ENUM('MODERE','EQUILIBRE','AUDACIEUX') NOT NULL DEFAULT 'MODERE'");
    }
};
