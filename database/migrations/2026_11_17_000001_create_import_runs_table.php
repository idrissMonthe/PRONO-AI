<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64)->index();
            $table->string('scope', 120)->nullable();
            $table->string('status', 20)->default('running'); // running, success, partial, failed
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('divergent')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at_utc')->nullable();
            $table->timestamp('finished_at_utc')->nullable();
            $table->timestamps();

            $table->index(['source', 'started_at_utc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
