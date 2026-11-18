<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_match_stats', function (Blueprint $table) {
            // Sans provenance, un import de corners/cartons ne pouvait pas
            // savoir s'il écrasait un fait StatsBomb ou une valeur absente.
            $table->string('provider', 30)->nullable()->after('team_id');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::table('team_match_stats', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropColumn('provider');
        });
    }
};
