<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonnes réellement extractibles des événements StatsBomb et absentes du
 * schéma initial : hors-jeu par équipe et statistiques individuelles.
 *
 * Toutes les colonnes restent nullables : une statistique non observée doit
 * rester NULL, jamais 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_match_stats', function (Blueprint $table) {
            $table->unsignedSmallInteger('offsides')->nullable();
        });

        Schema::create('player_match_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_player_id', 64);
            $table->string('player_name');
            $table->string('position', 64)->nullable();
            $table->boolean('is_starter')->nullable();
            $table->unsignedSmallInteger('minutes_played')->nullable();
            $table->unsignedSmallInteger('goals')->nullable();
            $table->unsignedSmallInteger('shots')->nullable();
            $table->unsignedSmallInteger('shots_on_target')->nullable();
            $table->decimal('xg', 6, 3)->nullable();
            $table->unsignedSmallInteger('yellow_cards')->nullable();
            $table->unsignedSmallInteger('red_cards')->nullable();
            $table->timestamps();

            // Un même joueur ne peut avoir qu'une ligne par match et par
            // fournisseur : l'import est ainsi idempotent.
            $table->unique(['match_id', 'provider', 'external_player_id'], 'player_match_stats_identity');
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_match_stats');

        Schema::table('team_match_stats', function (Blueprint $table) {
            $table->dropColumn('offsides');
        });
    }
};
