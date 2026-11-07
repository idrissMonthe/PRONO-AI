<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table): void {
            $table->string('prediction_stage', 20)->default('final')->after('is_official');
            $table->string('published_market', 50)->nullable()->after('prediction_stage');
            $table->string('published_selection', 100)->nullable()->after('published_market');
            $table->decimal('published_line', 6, 2)->nullable()->after('published_selection');
            $table->decimal('published_probability', 6, 4)->nullable()->after('published_line');
            $table->string('review_decision', 30)->nullable()->after('published_probability');
            $table->string('review_confidence', 20)->nullable()->after('review_decision');
            $table->dateTime('reviewed_at_utc')->nullable()->after('review_confidence');
            $table->index(['prediction_stage', 'review_decision']);
        });

        Schema::create('prediction_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('initial_prediction_id')->constrained('predictions')->cascadeOnDelete();
            $table->foreignId('final_prediction_id')->nullable()->constrained('predictions')->nullOnDelete();
            $table->string('decision', 30);
            $table->string('provider', 50)->default('deterministic_fallback');
            $table->string('ai_model', 80)->nullable();
            $table->json('initial_candidate')->nullable();
            $table->json('ai_recommendation')->nullable();
            $table->json('recalculated_candidate')->nullable();
            $table->json('final_candidate')->nullable();
            $table->text('reasoning')->nullable();
            $table->json('supporting_factors')->nullable();
            $table->json('risk_factors')->nullable();
            $table->json('validation')->nullable();
            $table->dateTime('reviewed_at_utc');
            $table->timestamps();

            $table->unique('initial_prediction_id');
            $table->index(['decision', 'reviewed_at_utc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_revisions');
        Schema::table('predictions', function (Blueprint $table): void {
            $table->dropIndex(['prediction_stage', 'review_decision']);
            $table->dropColumn([
                'prediction_stage', 'published_market', 'published_selection',
                'published_line', 'published_probability', 'review_decision',
                'review_confidence', 'reviewed_at_utc',
            ]);
        });
    }
};
