<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version_name', 50)->unique();
            $table->string('family', 20)->default('pure'); // pure, hybrid
            $table->string('algorithm', 100); // Poisson, Dixon-Coles, Elo, Ensemble
            $table->json('hyperparameters')->nullable();
            $table->string('data_hash', 64)->nullable();
            $table->string('config_hash', 64)->nullable();
            $table->string('artifact_hash', 64)->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->date('training_range_start')->nullable();
            $table->date('training_range_end')->nullable();
            $table->json('validation_metrics')->nullable();
            $table->json('test_metrics')->nullable();
            $table->string('status', 30)->default('candidate'); // candidate, shadow, champion, archived, rejected
            $table->text('decision_reason')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('decided_by', 50)->default('system');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('calibration_models', function (Blueprint $table) {
            $table->id();
            $table->string('version_name', 50)->unique();
            $table->string('method', 50); // temperature_scaling, multinomial_logistic, isotonic, dirichlet
            $table->json('parameters')->nullable();
            $table->string('validation_range', 100)->nullable();
            $table->json('metrics_before')->nullable();
            $table->json('metrics_after')->nullable();
            $table->timestamps();
        });

        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->string('calibration_version', 50)->nullable();
            $table->boolean('is_official')->default(false)->index();
            $table->dateTime('freeze_time_utc')->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->string('content_hash', 64);
            $table->string('prev_hash', 64)->nullable();
            $table->decimal('home_lambda', 6, 4);
            $table->decimal('away_lambda', 6, 4);
            $table->decimal('truncated_residual_mass', 10, 8)->default(0.00000000);
            $table->decimal('prob_home', 6, 4); // Exact decimal
            $table->decimal('prob_draw', 6, 4);
            $table->decimal('prob_away', 6, 4);
            $table->json('intervals_json')->nullable(); // ci80, ci95
            $table->json('derived_markets_json')->nullable(); // over_2_5, btts_yes, double_chance, etc.
            $table->json('top_scores_json')->nullable(); // e.g. [{"score":"1-0", "p":0.11}, ...]
            $table->decimal('quality_score', 5, 2)->default(100.00);
            $table->string('quality_tier', 20)->default('usable');
            $table->string('confidence_tier', 20)->default('medium'); // low, medium, high
            $table->boolean('is_abstained')->default(false);
            $table->json('warnings_json')->nullable();
            $table->unsignedInteger('execution_time_ms')->default(0);
            $table->timestamps();

            $table->index(['match_id', 'is_official']);
            $table->index('content_hash');
        });

        Schema::create('feature_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_id')->constrained('predictions')->cascadeOnDelete();
            $table->dateTime('as_of')->index();
            $table->json('variables');
            $table->string('snapshot_hash', 64);
            $table->json('missing_indicators')->nullable();
            $table->timestamps();

            $table->index('snapshot_hash');
        });

        Schema::create('prediction_hash_chains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_id')->constrained('predictions')->cascadeOnDelete();
            $table->string('current_hash', 64)->unique();
            $table->string('prev_hash', 64);
            $table->dateTime('chained_at_utc');
            $table->timestamps();
        });

        Schema::create('model_promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_version_id')->nullable();
            $table->unsignedBigInteger('target_version_id');
            $table->string('decision', 30); // promote, reject, rollback
            $table->json('evaluated_criteria');
            $table->string('mode', 20)->default('manual'); // manual, automatic
            $table->unsignedBigInteger('rollback_of_id')->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();
        });

        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('family', 20)->default('pure');
            $table->string('config_hash', 64)->nullable();
            $table->string('data_hash', 64)->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('time_step', 30)->default('1_week');
            $table->json('metrics')->nullable(); // RPS, log loss, Brier, ECE, CLV
            $table->text('report_text')->nullable();
            $table->string('status', 30)->default('COMPLETED'); // RUNNING, COMPLETED, FAILED
            $table->timestamps();
        });

        Schema::create('drift_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->date('window_start');
            $table->date('window_end');
            $table->json('psi_metrics')->nullable();
            $table->decimal('rolling_calibration_ece', 6, 4)->nullable();
            $table->decimal('rolling_rps', 6, 4)->nullable();
            $table->string('alert_level', 20)->default('info'); // info, warning, critical
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drift_reports');
        Schema::dropIfExists('backtest_runs');
        Schema::dropIfExists('model_promotions');
        Schema::dropIfExists('prediction_hash_chains');
        Schema::dropIfExists('feature_snapshots');
        Schema::dropIfExists('predictions');
        Schema::dropIfExists('calibration_models');
        Schema::dropIfExists('model_versions');
    }
};
