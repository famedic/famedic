<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_result_extraction_qa_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_result_version_id');
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

            $table->index('laboratory_result_version_id', 'lab_res_qa_metrics_version_idx');
            $table->index('ai_execution_id', 'lab_res_qa_metrics_ai_exec_idx');

            $table->foreign('laboratory_result_version_id', 'lab_res_qa_metrics_version_fk')
                ->references('id')
                ->on('laboratory_result_versions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_extraction_qa_metrics');
    }
};
