<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->string('country', 100);
            $table->string('tier', 20)->default('1');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained('leagues')->cascadeOnDelete();
            $table->string('name', 50); // e.g. "2023-2024"
            $table->integer('year_start');
            $table->integer('year_end');
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['league_id', 'name']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->nullable()->constrained('leagues')->nullOnDelete();
            $table->string('name');
            $table->string('short_name', 50)->nullable();
            $table->string('code', 20)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('team_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('alias');
            $table->string('source', 50)->default('internal');
            $table->decimal('confidence', 5, 2)->default(100.00);
            $table->boolean('is_validated')->default(true);
            $table->timestamps();

            $table->unique(['alias', 'source']);
            $table->index('alias');
        });

        Schema::create('provider_entity_maps', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50); // team, match, league
            $table->unsignedBigInteger('internal_id');
            $table->string('provider', 50); // football-data-org, api-football, etc.
            $table->string('external_id', 100);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'provider', 'external_id']);
            $table->index(['entity_type', 'internal_id']);
        });

        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained('leagues')->cascadeOnDelete();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            $table->foreignId('home_team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('away_team_id')->constrained('teams')->cascadeOnDelete();
            $table->dateTime('match_date_utc')->index();
            $table->string('status', 30)->default('SCHEDULED')->index(); // SCHEDULED, TIMED, IN_PLAY, PAUSED, FINISHED, POSTPONED, CANCELLED
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->unsignedSmallInteger('ht_home_score')->nullable();
            $table->unsignedSmallInteger('ht_away_score')->nullable();
            $table->boolean('is_neutral_ground')->default(false);
            $table->string('round', 50)->nullable();
            $table->decimal('quality_score', 5, 2)->default(100.00);
            $table->string('quality_tier', 20)->default('usable'); // usable, degraded, insufficient
            $table->timestamps();

            $table->index(['home_team_id', 'match_date_utc']);
            $table->index(['away_team_id', 'match_date_utc']);
        });

        Schema::create('team_match_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->boolean('is_home')->default(true);
            $table->unsignedSmallInteger('shots')->nullable();
            $table->unsignedSmallInteger('shots_on_target')->nullable();
            $table->unsignedSmallInteger('corners')->nullable();
            $table->unsignedSmallInteger('fouls')->nullable();
            $table->unsignedSmallInteger('yellow_cards')->nullable();
            $table->unsignedSmallInteger('red_cards')->nullable();
            $table->decimal('xg', 5, 2)->nullable();
            $table->decimal('possession_pct', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'team_id']);
        });

        Schema::create('team_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->date('as_of_date')->index();
            $table->decimal('elo_rating', 8, 2)->default(1500.00);
            $table->decimal('attack_strength', 8, 4)->default(1.0000);
            $table->decimal('defense_strength', 8, 4)->default(1.0000);
            $table->timestamps();

            $table->unique(['team_id', 'as_of_date']);
        });

        Schema::create('player_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('player_name');
            $table->string('status', 30); // confirmed_absent, doubtful, confirmed_starting
            $table->string('source', 50)->default('manual');
            $table->dateTime('reported_at_utc');
            $table->timestamps();

            $table->index(['match_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_availabilities');
        Schema::dropIfExists('team_ratings');
        Schema::dropIfExists('team_match_stats');
        Schema::dropIfExists('matches');
        Schema::dropIfExists('provider_entity_maps');
        Schema::dropIfExists('team_aliases');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('seasons');
        Schema::dropIfExists('leagues');
    }
};
