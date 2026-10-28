<?php

namespace Tests\Feature\Laboratory;

use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait AiExplanationIsolatedSchema
{
    protected function bootstrapAiExplanationSchema(): void
    {
        Schema::dropIfExists('laboratory_result_ai_explanations');
        Schema::dropIfExists('customer_laboratory_ai_explanation_consents');

        if (! Schema::hasTable('ai_prompts')) {
            Schema::create('ai_prompts', function (Blueprint $table) {
                $table->id();
                $table->string('key');
                $table->string('domain', 80);
                $table->unsignedInteger('version');
                $table->string('status', 40)->default('draft');
                $table->string('model')->nullable();
                $table->longText('system_prompt');
                $table->longText('user_prompt');
                $table->json('response_schema')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_executions')) {
            Schema::create('ai_executions', function (Blueprint $table) {
                $table->id();
                $table->string('domain', 80);
                $table->string('feature', 120);
                $table->string('subject_type')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->unsignedBigInteger('prompt_id')->nullable();
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
            });
        }

        Schema::create('laboratory_result_ai_explanations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_result_observation_id');
            $table->string('status', 30);
            $table->text('explanation')->nullable();
            $table->text('limitations')->nullable();
            $table->string('input_hash', 64);
            $table->unsignedInteger('prompt_version');
            $table->unsignedBigInteger('ai_execution_id')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['laboratory_result_observation_id', 'input_hash', 'prompt_version'],
                'lab_result_ai_expl_obs_hash_prompt_unique',
            );
        });

        Schema::create('customer_laboratory_ai_explanation_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 30);
            $table->string('consent_version', 40);
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();
        });

        DB::table('ai_prompts')->updateOrInsert(
            [
                'key' => LaboratoryResultAiExplanationContract::PROMPT_KEY,
                'version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
            ],
            [
                'domain' => LaboratoryResultAiExplanationContract::DOMAIN,
                'status' => 'active',
                'model' => 'gpt-4o-mini',
                'system_prompt' => 'test system',
                'user_prompt' => '{{input_json}}',
                'response_schema' => json_encode(LaboratoryResultAiExplanationContract::outputJsonSchema()),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    protected function tearDownAiExplanationSchema(): void
    {
        Schema::dropIfExists('laboratory_result_ai_explanations');
        Schema::dropIfExists('customer_laboratory_ai_explanation_consents');
    }
}
