<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmakers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('type', 30)->default('high_margin'); // low_margin, high_margin, exchange
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // 1N2, double_chance, dnb, totals, btts, etc.
            $table->string('name');
            $table->string('category', 50)->default('main');
            $table->timestamps();
        });

        Schema::create('odds_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('bookmaker_id')->constrained('bookmakers')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->string('selection', 50); // HOME, DRAW, AWAY, OVER, UNDER, YES, NO
            $table->decimal('line', 6, 2)->nullable(); // e.g. 2.5 for Over/Under
            $table->decimal('decimal_odds', 8, 4); // Exact decimal type per CDC 13.4
            $table->dateTime('snapshot_time_utc')->index();
            $table->boolean('is_opening')->default(false);
            $table->boolean('is_closing')->default(false);
            $table->string('source', 50)->default('football-data-uk');
            $table->string('raw_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['match_id', 'market_id', 'is_closing']);
            $table->index(['match_id', 'bookmaker_id']);
        });

        Schema::create('paper_bets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prediction_id')->nullable();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('market', 50); // 1N2, OVER_2_5, BTTS_YES, etc.
            $table->string('selection', 50);
            $table->decimal('line', 6, 2)->nullable();
            $table->decimal('placed_odds', 8, 4);
            $table->decimal('closing_odds', 8, 4)->nullable();
            $table->foreignId('odds_snapshot_id')->nullable()->constrained('odds_snapshots')->nullOnDelete();
            $table->string('staking_rule', 50)->default('flat_1u'); // flat_1u, proportional_edge, fractional_kelly
            $table->decimal('stake_units', 8, 4)->default(1.0000);
            $table->string('status', 30)->default('PENDING'); // PENDING, WON, LOST, VOID, CANCELLED
            $table->decimal('virtual_profit', 10, 4)->nullable();
            $table->decimal('clv', 8, 4)->nullable();
            $table->dateTime('placed_at_utc');
            $table->dateTime('settled_at_utc')->nullable();
            $table->timestamps();

            $table->index(['status', 'placed_at_utc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_bets');
        Schema::dropIfExists('odds_snapshots');
        Schema::dropIfExists('markets');
        Schema::dropIfExists('bookmakers');
    }
};
