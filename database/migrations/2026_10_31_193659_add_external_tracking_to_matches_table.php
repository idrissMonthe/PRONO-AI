<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // external_id : identifiant unique fourni par l'API (ex: football-data.org match ID)
            $table->string('external_id', 64)->nullable()->unique()->after('id');
            // data_source : source des données ('football-data.org', 'seeder', 'manual', etc.)
            $table->string('data_source', 64)->default('manual')->after('external_id')->index();
            // last_synced_at : date de dernière synchronisation depuis l'API
            $table->timestamp('last_synced_at')->nullable()->after('data_source');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'data_source', 'last_synced_at']);
        });
    }
};
