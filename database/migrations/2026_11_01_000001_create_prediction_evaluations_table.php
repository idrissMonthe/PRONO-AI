<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_id')->constrained('predictions')->cascadeOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('market_group', 30);
            $table->string('market_key', 60);
            $table->string('predicted_label', 160);
            $table->decimal('predicted_probability', 6, 4);
            $table->string('actual_label', 160);
            $table->string('status', 10); // WON / LOST
            $table->dateTime('evaluated_at_utc');
            $table->timestamps();

            $table->unique(['prediction_id', 'market_key']);
            $table->index(['market_group', 'status']);
            $table->index('evaluated_at_utc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_evaluations');
    }
};
