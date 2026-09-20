<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_result_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_purchase_id');
            $table->unsignedBigInteger('laboratory_result_version_id')->nullable();
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
            /**
             * MySQL no soporta índices únicos parciales (WHERE structured_status = published).
             * Este slot nullable replica laboratory_result_version_id solo mientras el report
             * es el publicado activo de esa versión. NULL en otros estados; UNIQUE garantiza
             * un solo report activo por versión documental.
             */
            $table->unsignedBigInteger('published_version_slot')->nullable();
            $table->timestamps();

            $table->foreign('laboratory_purchase_id', 'lab_res_report_purchase_fk')
                ->references('id')->on('laboratory_purchases')->cascadeOnDelete();
            $table->foreign('laboratory_result_version_id', 'lab_res_report_version_fk')
                ->references('id')->on('laboratory_result_versions')->nullOnDelete();
            $table->foreign('superseded_by_report_id', 'lab_res_report_superseded_fk')
                ->references('id')->on('laboratory_result_reports')->nullOnDelete();
            $table->foreign('ai_execution_id', 'lab_res_report_ai_exec_fk')
                ->references('id')->on('ai_executions')->nullOnDelete();

            $table->unique(
                ['laboratory_result_version_id', 'input_hash'],
                'lab_result_report_version_input_hash_unique'
            );
            $table->unique('published_version_slot', 'lab_result_report_published_slot_unique');
            $table->index(
                ['laboratory_purchase_id', 'structured_status'],
                'lab_result_report_purchase_structured_idx'
            );
            $table->index('extraction_status', 'lab_result_report_extraction_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_reports');
    }
};
