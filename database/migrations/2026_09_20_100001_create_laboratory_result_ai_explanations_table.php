<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('laboratory_result_ai_explanations')) {
            $this->ensureConstraints();

            return;
        }

        Schema::create('laboratory_result_ai_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_result_observation_id');
            $table->string('status', 30);
            $table->text('explanation')->nullable();
            $table->text('limitations')->nullable();
            $table->string('input_hash', 64);
            $table->unsignedInteger('prompt_version');
            $table->foreignId('ai_execution_id')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['laboratory_result_observation_id', 'input_hash', 'prompt_version'],
                'lab_result_ai_expl_obs_hash_prompt_unique',
            );
            $table->index(['status', 'generated_at'], 'lab_result_ai_expl_status_generated_idx');

            $table->foreign('laboratory_result_observation_id', 'lrae_observation_fk')
                ->references('id')
                ->on('laboratory_result_observations')
                ->cascadeOnDelete();
            $table->foreign('ai_execution_id', 'lrae_ai_execution_fk')
                ->references('id')
                ->on('ai_executions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_ai_explanations');
    }

    private function ensureConstraints(): void
    {
        Schema::table('laboratory_result_ai_explanations', function (Blueprint $table) {
            if (! $this->indexExists('laboratory_result_ai_explanations', 'lab_result_ai_expl_obs_hash_prompt_unique')) {
                $table->unique(
                    ['laboratory_result_observation_id', 'input_hash', 'prompt_version'],
                    'lab_result_ai_expl_obs_hash_prompt_unique',
                );
            }

            if (! $this->indexExists('laboratory_result_ai_explanations', 'lab_result_ai_expl_status_generated_idx')) {
                $table->index(['status', 'generated_at'], 'lab_result_ai_expl_status_generated_idx');
            }

            if (! $this->foreignKeyExists('laboratory_result_ai_explanations', 'lrae_observation_fk')) {
                $table->foreign('laboratory_result_observation_id', 'lrae_observation_fk')
                    ->references('id')
                    ->on('laboratory_result_observations')
                    ->cascadeOnDelete();
            }

            if (! $this->foreignKeyExists('laboratory_result_ai_explanations', 'lrae_ai_execution_fk')) {
                $table->foreign('ai_execution_id', 'lrae_ai_execution_fk')
                    ->references('id')
                    ->on('ai_executions')
                    ->nullOnDelete();
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return $connection->select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$database, $table, $indexName],
        ) !== [];
    }

    private function foreignKeyExists(string $table, string $foreignName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return $connection->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$database, $table, $foreignName, 'FOREIGN KEY'],
        ) !== [];
    }
};
