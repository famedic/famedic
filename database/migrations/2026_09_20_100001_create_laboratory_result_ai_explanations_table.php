<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_result_ai_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_result_observation_id')
                ->constrained('laboratory_result_observations')
                ->cascadeOnDelete();
            $table->string('status', 30);
            $table->text('explanation')->nullable();
            $table->text('limitations')->nullable();
            $table->string('input_hash', 64);
            $table->unsignedInteger('prompt_version');
            $table->foreignId('ai_execution_id')->nullable()->constrained('ai_executions')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['laboratory_result_observation_id', 'input_hash', 'prompt_version'],
                'lab_result_ai_expl_obs_hash_prompt_unique',
            );
            $table->index(['status', 'generated_at'], 'lab_result_ai_expl_status_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_ai_explanations');
    }
};
