<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_executions', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 80);
            $table->string('feature', 120);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('prompt_id')->nullable()->constrained('ai_prompts')->nullOnDelete();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('model')->nullable();
            $table->string('status', 40);
            $table->string('input_hash', 64)->nullable();
            $table->json('request_payload_redacted')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['domain', 'feature', 'status'], 'ai_executions_domain_feature_status_idx');
            $table->index(['subject_type', 'subject_id'], 'ai_executions_subject_idx');
            $table->index('input_hash', 'ai_executions_input_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_executions');
    }
};
