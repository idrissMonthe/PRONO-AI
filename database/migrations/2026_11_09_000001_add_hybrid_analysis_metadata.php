<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->string('analysis_status', 30)->default('NO_ANALYSIS')->after('quality_tier');
            $table->string('analysis_source', 40)->nullable()->after('analysis_status');
            $table->text('analysis_reason')->nullable()->after('analysis_source');
            $table->json('analysis_missing_json')->nullable()->after('analysis_reason');
            $table->dateTime('analysis_updated_at')->nullable()->after('analysis_missing_json');
            $table->index('analysis_status');
        });

        Schema::table('predictions', function (Blueprint $table): void {
            $table->string('prediction_origin', 30)->default('PYTHON_ESTIMATE')->after('prediction_stage');
            $table->string('source_model', 100)->nullable()->after('prediction_origin');
            $table->json('ai_estimate_json')->nullable()->after('source_model');
            $table->json('missing_data_json')->nullable()->after('ai_estimate_json');
            $table->string('uncertainty_tier', 20)->nullable()->after('missing_data_json');
            $table->index(['prediction_origin', 'is_official']);
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table): void {
            $table->dropIndex(['prediction_origin', 'is_official']);
            $table->dropColumn([
                'prediction_origin', 'source_model', 'ai_estimate_json',
                'missing_data_json', 'uncertainty_tier',
            ]);
        });

        Schema::table('matches', function (Blueprint $table): void {
            $table->dropIndex(['analysis_status']);
            $table->dropColumn([
                'analysis_status', 'analysis_source', 'analysis_reason',
                'analysis_missing_json', 'analysis_updated_at',
            ]);
        });
    }
};
