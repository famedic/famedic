<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_store_import_resolutions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64)->default('gda');
            $table->string('brand', 64);
            $table->string('source_name');
            $table->string('normalized_source_name');
            $table->string('external_key')->nullable();
            $table->string('source_file_hash', 64)->nullable();
            $table->string('decision', 32);
            $table->foreignId('matched_store_id')->nullable()->constrained('laboratory_stores')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'brand', 'normalized_source_name'], 'lsir_source_brand_name_idx');
            $table->index(['source', 'brand', 'normalized_source_name', 'external_key'], 'lsir_source_brand_key_idx');
            $table->index(['source_file_hash', 'superseded_at'], 'lsir_file_hash_current_idx');
            $table->index(['decision', 'superseded_at'], 'lsir_decision_current_idx');
            $table->index('matched_store_id', 'lsir_matched_store_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_store_import_resolutions');
    }
};
