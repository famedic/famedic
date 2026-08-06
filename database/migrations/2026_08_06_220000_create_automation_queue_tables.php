<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('automation_uuid')->unique();
            $table->string('driver', 128);
            $table->string('driver_class')->nullable();
            $table->string('handler', 64);
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('channel', 32)->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 32)->index(); // pending|running|retrying|completed|failed|dead_letter
            $table->boolean('retryable')->nullable();
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['driver', 'status']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['channel', 'created_at']);
        });

        Schema::create('automation_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->uuid('automation_uuid')->unique();
            $table->foreignId('automation_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->string('driver', 128);
            $table->string('handler', 64)->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->text('stack')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_execution_at')->nullable();
            $table->string('status', 32)->default('open')->index(); // open|requeued|discarded
            $table->timestamp('requeued_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->foreignId('discarded_by')->nullable();
            $table->timestamps();

            $table->index(['driver', 'status']);
        });

        Schema::create('automation_retry_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('automation_uuid')->index();
            $table->foreignId('automation_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_retry_history');
        Schema::dropIfExists('automation_dead_letters');
        Schema::dropIfExists('automation_runs');
    }
};
