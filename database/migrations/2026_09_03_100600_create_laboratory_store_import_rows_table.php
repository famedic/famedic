<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_store_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('laboratory_store_import_runs')->cascadeOnDelete();
            $table->string('excel_sheet');
            $table->unsignedInteger('excel_row');
            $table->string('brand', 64)->nullable()->index('lsirr_brand_idx');
            $table->string('source_name')->nullable();
            $table->string('normalized_name')->nullable();
            $table->foreignId('matched_store_id')->nullable()->constrained('laboratory_stores')->nullOnDelete();
            $table->string('classification', 32);
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('action', 32);
            $table->json('diff')->nullable();
            $table->json('errors')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'excel_sheet', 'excel_row'], 'lsirr_run_sheet_row_unique');
            $table->index(['run_id', 'classification'], 'lsirr_run_classification_idx');
            $table->index('matched_store_id', 'lsirr_matched_store_idx');
            $table->index(['brand', 'normalized_name'], 'lsirr_brand_normalized_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_store_import_rows');
    }
};
