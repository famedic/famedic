<?php

namespace Tests\Feature\Laboratory;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait StructuredResultsIsolatedSchema
{
    protected function bootstrapStructuredResultsSchema(): void
    {
        Schema::dropIfExists('laboratory_result_observations');
        Schema::dropIfExists('laboratory_result_reports');
        Schema::dropIfExists('laboratory_analyte_aliases');
        Schema::dropIfExists('laboratory_analytes');
        Schema::dropIfExists('laboratory_result_extraction_qa_metrics');
        Schema::dropIfExists('ai_executions');
        Schema::dropIfExists('ai_prompts');

        Schema::create('laboratory_analytes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('canonical_name');
            $table->string('loinc_code', 40)->nullable()->unique();
            $table->string('default_unit', 40)->nullable();
            $table->string('value_kind', 20);
            $table->string('category', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('laboratory_analyte_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_analyte_id')->constrained('laboratory_analytes')->cascadeOnDelete();
            $table->string('alias_normalized', 191)->unique();
            $table->string('alias_raw')->nullable();
            $table->string('source', 20);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('laboratory_result_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->constrained('laboratory_purchases')->cascadeOnDelete();
            $table->foreignId('laboratory_result_version_id')->nullable()->constrained('laboratory_result_versions')->nullOnDelete();
            $table->string('source', 30);
            $table->string('extraction_method', 30);
            $table->string('extraction_status', 30);
            $table->string('structured_status', 30);
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('specimen_collected_at')->nullable();
            $table->decimal('confidence_overall', 5, 4)->nullable();
            $table->unsignedInteger('observation_count')->default(0);
            $table->json('validation_errors')->nullable();
            $table->json('raw_extraction_payload')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_report_id')->nullable();
            $table->unsignedBigInteger('ai_execution_id')->nullable();
            $table->string('input_hash', 64);
            $table->string('extractor_version', 40)->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->unsignedBigInteger('published_version_slot')->nullable();
            $table->timestamps();

            $table->unique(['laboratory_result_version_id', 'input_hash'], 'lab_result_report_version_input_hash_unique');
            $table->unique('published_version_slot', 'lab_result_report_published_slot_unique');
        });

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
        });

        Schema::create('laboratory_result_extraction_qa_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_result_version_id')->constrained('laboratory_result_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('text_report_id')->nullable();
            $table->unsignedBigInteger('vision_report_id')->nullable();
            $table->string('comparison_outcome', 40);
            $table->unsignedInteger('text_observation_count')->default(0);
            $table->unsignedInteger('vision_observation_count')->default(0);
            $table->unsignedInteger('match_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('vision_only_count')->default(0);
            $table->unsignedInteger('text_only_count')->default(0);
            $table->unsignedInteger('unresolved_count')->default(0);
            $table->decimal('match_rate', 8, 4)->nullable();
            $table->decimal('conflict_rate', 8, 4)->nullable();
            $table->decimal('vision_coverage', 8, 4)->nullable();
            $table->decimal('vision_only_rate', 8, 4)->nullable();
            $table->json('fallback_reasons')->nullable();
            $table->string('text_extraction_status', 30)->nullable();
            $table->string('vision_extraction_status', 30)->nullable();
            $table->decimal('vision_confidence_avg', 5, 4)->nullable();
            $table->unsignedSmallInteger('vision_page_count')->nullable();
            $table->unsignedBigInteger('ai_execution_id')->nullable();
            $table->string('text_extractor_version', 40)->nullable();
            $table->string('vision_extractor_version', 40)->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->boolean('shadow_mode')->default(true);
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('laboratory_result_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_result_report_id')->constrained('laboratory_result_reports')->cascadeOnDelete();
            $table->foreignId('laboratory_analyte_id')->nullable()->constrained('laboratory_analytes')->nullOnDelete();
            $table->string('analyte_code', 120)->nullable();
            $table->string('analyte_name_raw');
            $table->string('analyte_name_display')->nullable();
            $table->decimal('numeric_value', 16, 6)->nullable();
            $table->string('text_value')->nullable();
            $table->string('value_type', 20);
            $table->string('unit', 40)->nullable();
            $table->string('unit_raw', 40)->nullable();
            $table->decimal('reference_low', 16, 6)->nullable();
            $table->decimal('reference_high', 16, 6)->nullable();
            $table->string('reference_text')->nullable();
            $table->string('reference_status', 30);
            $table->boolean('abnormal_flag')->nullable();
            $table->string('abnormal_source', 20)->nullable();
            $table->foreignId('laboratory_purchase_item_id')->nullable()->constrained('laboratory_purchase_items')->nullOnDelete();
            $table->string('panel_name_raw')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('extraction_method', 30);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->json('source_bbox')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDownStructuredResultsSchema(): void
    {
        Schema::dropIfExists('laboratory_result_observations');
        Schema::dropIfExists('laboratory_result_reports');
        Schema::dropIfExists('laboratory_analyte_aliases');
        Schema::dropIfExists('laboratory_analytes');
        Schema::dropIfExists('laboratory_result_extraction_qa_metrics');
        Schema::dropIfExists('ai_executions');
        Schema::dropIfExists('ai_prompts');
    }
}
