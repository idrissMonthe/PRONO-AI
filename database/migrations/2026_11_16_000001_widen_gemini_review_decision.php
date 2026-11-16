<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table): void {
            $table->string('review_decision', 50)->nullable()->change();
        });

        Schema::table('prediction_revisions', function (Blueprint $table): void {
            $table->string('decision', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table): void {
            $table->string('review_decision', 30)->nullable()->change();
        });

        Schema::table('prediction_revisions', function (Blueprint $table): void {
            $table->string('decision', 30)->change();
        });
    }
};
