<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_result_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_result_report_id');
            $table->unsignedBigInteger('laboratory_analyte_id')->nullable();
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
            $table->unsignedBigInteger('laboratory_purchase_item_id')->nullable();
            $table->string('panel_name_raw')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('extraction_method', 30);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->json('source_bbox')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('laboratory_result_report_id', 'lab_res_obs_report_fk')
                ->references('id')->on('laboratory_result_reports')->cascadeOnDelete();
            $table->foreign('laboratory_analyte_id', 'lab_res_obs_analyte_fk')
                ->references('id')->on('laboratory_analytes')->nullOnDelete();
            $table->foreign('laboratory_purchase_item_id', 'lab_res_obs_item_fk')
                ->references('id')->on('laboratory_purchase_items')->nullOnDelete();

            $table->index(
                ['laboratory_result_report_id', 'laboratory_analyte_id'],
                'lab_result_obs_report_analyte_idx'
            );
            $table->index('analyte_code', 'lab_result_obs_analyte_code_idx');
            $table->index('laboratory_purchase_item_id', 'lab_result_obs_purchase_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_observations');
    }
};
