<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->json('quality_breakdown')->nullable()->after('quality_tier');
            $table->index(['data_source', 'last_synced_at']);
        });

        Schema::table('predictions', function (Blueprint $table): void {
            $table->json('market_metrics_json')->nullable()->after('warnings_json');
            $table->index(['model_version_id', 'is_abstained', 'created_at']);
        });

        Schema::table('prediction_evaluations', function (Blueprint $table): void {
            $table->decimal('brier_score', 8, 6)->nullable()->after('predicted_probability');
            $table->decimal('log_loss', 8, 6)->nullable()->after('brier_score');
            $table->string('probability_bucket', 20)->nullable()->after('log_loss');
            $table->index(['market_group', 'evaluated_at_utc']);
        });

        Schema::create('system_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            $table->string('severity', 20)->default('warning');
            $table->string('status', 20)->default('open');
            $table->string('source', 50)->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->dateTime('last_seen_at_utc');
            $table->dateTime('resolved_at_utc')->nullable();
            $table->timestamps();

            $table->unique(['key', 'status']);
            $table->index(['severity', 'status']);
            $table->index('last_seen_at_utc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');

        Schema::table('prediction_evaluations', function (Blueprint $table): void {
            $table->dropIndex(['market_group', 'evaluated_at_utc']);
            $table->dropColumn(['brier_score', 'log_loss', 'probability_bucket']);
        });

        Schema::table('predictions', function (Blueprint $table): void {
            $table->dropIndex(['model_version_id', 'is_abstained', 'created_at']);
            $table->dropColumn('market_metrics_json');
        });

        Schema::table('matches', function (Blueprint $table): void {
            $table->dropIndex(['data_source', 'last_synced_at']);
            $table->dropColumn('quality_breakdown');
        });
    }
};
