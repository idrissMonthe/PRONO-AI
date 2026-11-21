<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite ne sait pas modifier une contrainte existante. Les tests
        // repartent d'un schéma neuf, déjà créé en RESTRICT par la migration
        // d'origine.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('predictions', function (Blueprint $table) {
            $table->dropForeign(['match_id']);
        });
        Schema::table('predictions', function (Blueprint $table) {
            $table->foreign('match_id')->references('id')->on('matches')->restrictOnDelete();
        });

        Schema::table('prediction_hash_chains', function (Blueprint $table) {
            $table->dropForeign(['prediction_id']);
        });
        Schema::table('prediction_hash_chains', function (Blueprint $table) {
            $table->foreign('prediction_id')->references('id')->on('predictions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('predictions', function (Blueprint $table) {
            $table->dropForeign(['match_id']);
        });
        Schema::table('predictions', function (Blueprint $table) {
            $table->foreign('match_id')->references('id')->on('matches')->cascadeOnDelete();
        });

        Schema::table('prediction_hash_chains', function (Blueprint $table) {
            $table->dropForeign(['prediction_id']);
        });
        Schema::table('prediction_hash_chains', function (Blueprint $table) {
            $table->foreign('prediction_id')->references('id')->on('predictions')->cascadeOnDelete();
        });
    }
};
