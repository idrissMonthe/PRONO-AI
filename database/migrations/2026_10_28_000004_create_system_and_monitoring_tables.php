<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_quarantines', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);
            $table->string('entity_type', 50);
            $table->string('raw_hash', 64)->nullable();
            $table->text('rejection_reason');
            $table->json('raw_payload')->nullable();
            $table->dateTime('quarantined_at_utc');
            $table->timestamps();
        });

        Schema::create('provider_quotas', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique();
            $table->unsignedInteger('daily_limit')->default(100);
            $table->unsignedInteger('calls_used_today')->default(0);
            $table->dateTime('reset_at_utc');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('api_call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('endpoint');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms');
            $table->integer('quota_remaining')->nullable();
            $table->dateTime('called_at_utc');
            $table->timestamps();

            $table->index(['provider', 'called_at_utc']);
        });

        Schema::create('llm_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->default('deterministic_template');
            $table->string('model', 50)->nullable();
            $table->string('context_hash', 64);
            $table->text('sanitized_prompt');
            $table->text('response_text');
            $table->boolean('verification_passed')->default(true);
            $table->json('verification_details')->nullable();
            $table->dateTime('interacted_at_utc');
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value_json');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 100);
            $table->string('actor', 50)->default('system');
            $table->json('details');
            $table->dateTime('occurred_at_utc');
            $table->timestamps();

            $table->index(['event_type', 'occurred_at_utc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('llm_interactions');
        Schema::dropIfExists('api_call_logs');
        Schema::dropIfExists('provider_quotas');
        Schema::dropIfExists('data_quarantines');
    }
};
