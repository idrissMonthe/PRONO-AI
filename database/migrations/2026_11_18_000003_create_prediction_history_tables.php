<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historisation immuable des pronostics publiés.
 *
 * Un snapshot n'est jamais modifié : une nouvelle génération crée de
 * nouvelles lignes. Le règlement vit dans une table séparée, ce qui permet de
 * conserver intacte la probabilité annoncée même après la fin du match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->dateTime('match_date_utc')->nullable();
            $table->string('competition_code', 20)->nullable();
            $table->string('season', 50)->nullable();
            $table->dateTime('generated_at_utc')->index();
            $table->string('model_version', 64)->nullable();
            $table->string('calibration_version', 50)->nullable();
            $table->string('feature_version', 50)->nullable();
            $table->dateTime('features_as_of')->nullable();
            $table->json('data_sources')->nullable();
            $table->string('payload_hash', 64)->index();
            $table->unsignedInteger('prediction_count')->default(0);
            $table->string('status', 20)->default('published');
            $table->json('context_json')->nullable();
            $table->timestamps();
        });

        Schema::create('prediction_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_run_id')->constrained('forecast_runs')->cascadeOnDelete();
            $table->foreignId('prediction_id')->nullable()->constrained('predictions')->nullOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('market_key', 60);
            $table->string('market_group', 40)->nullable();
            $table->string('selection', 120);
            $table->decimal('line', 6, 2)->nullable();
            $table->string('category', 20)->nullable(); // safe, balanced, bold
            $table->decimal('probability', 8, 6);
            $table->decimal('fair_odds', 8, 3)->nullable();
            $table->decimal('odds_at_publication', 8, 4)->nullable();
            $table->string('odds_provider', 50)->nullable();
            $table->dateTime('odds_timestamp_utc')->nullable();
            $table->string('model_version', 64)->nullable();
            $table->string('calibration_version', 50)->nullable();
            $table->string('feature_version', 50)->nullable();
            $table->string('coverage_status', 30)->default('covered');
            $table->string('reliability', 20)->nullable();
            $table->json('data_sources')->nullable();
            $table->json('context_json')->nullable();
            $table->dateTime('published_at_utc')->index();
            // Un même contenu publié deux fois ne crée pas deux snapshots :
            // la contrainte garantit l'idempotence sans jamais écraser.
            $table->string('content_hash', 64)->unique();
            $table->timestamps();

            $table->index(['match_id', 'market_key']);
            $table->index(['market_group', 'category']);
        });

        Schema::create('prediction_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_snapshot_id')->unique()->constrained('prediction_snapshots')->cascadeOnDelete();
            $table->string('outcome', 20)->default('PENDING'); // WON, LOST, VOID, HALF_WON, HALF_LOST, PENDING
            $table->string('actual_label', 160)->nullable();
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->dateTime('settled_at_utc')->nullable();
            $table->string('source', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['outcome', 'settled_at_utc']);
        });

        Schema::create('combination_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_run_id')->nullable()->constrained('forecast_runs')->nullOnDelete();
            $table->unsignedBigInteger('parlay_id')->nullable();
            $table->string('title', 120);
            $table->string('risk_level', 20);
            $table->decimal('combined_probability', 8, 6);
            $table->decimal('combined_odds', 8, 4)->nullable();
            $table->decimal('expected_value', 8, 4)->nullable();
            $table->string('model_version', 64)->nullable();
            $table->date('target_date')->nullable()->index();
            $table->unsignedInteger('legs_count')->default(0);
            $table->string('status', 20)->default('PENDING'); // PENDING, WON, LOST, VOID, PARTIAL
            $table->dateTime('published_at_utc')->index();
            $table->dateTime('settled_at_utc')->nullable();
            $table->string('content_hash', 64)->unique();
            $table->json('context_json')->nullable();
            $table->timestamps();
        });

        Schema::create('combination_snapshot_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combination_snapshot_id')->constrained('combination_snapshots')->cascadeOnDelete();
            $table->foreignId('prediction_snapshot_id')->nullable()->constrained('prediction_snapshots')->nullOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->string('market_key', 60);
            $table->string('selection', 120);
            $table->decimal('probability', 8, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combination_snapshot_legs');
        Schema::dropIfExists('combination_snapshots');
        Schema::dropIfExists('prediction_settlements');
        Schema::dropIfExists('prediction_snapshots');
        Schema::dropIfExists('forecast_runs');
    }
};
