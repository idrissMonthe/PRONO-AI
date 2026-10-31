<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parlays', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->date('date_for')->index();
            $table->enum('risk_level', ['MODERE', 'EQUILIBRE', 'AUDACIEUX'])->default('MODERE');
            $table->decimal('risk_score', 4, 1)->comment('Score de risque de 1.0 (faible) à 10.0 (très élevé) basé sur la probabilité conjointe');
            $table->decimal('combined_odds', 8, 3)->comment('Cote totale calculée par produit des cotes réelles');
            $table->decimal('combined_prob', 6, 4)->comment('Probabilité conjointe calculée par le modèle');
            $table->decimal('expected_value', 6, 4)->nullable()->comment('Espérance mathématique (EV)');
            $table->json('selections_json')->comment('Liste structurée des sélections réelles avec sources de cotes et versions modèle');
            $table->string('model_version', 64)->default('M3_DIXON_COLES');
            $table->text('ai_analysis')->nullable()->comment('Analyse probabiliste transparente générée par Gemini');
            $table->string('ai_model_used', 64)->nullable();
            $table->enum('status', ['PENDING', 'WON', 'LOST', 'VOID', 'EXPIRED'])->default('PENDING');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parlays');
    }
};
