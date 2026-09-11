<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_store_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('file_path');
            $table->string('file_hash', 64)->index('lsir_file_hash_idx');
            $table->string('brand_filter', 64)->nullable()->index('lsir_brand_filter_idx');
            $table->boolean('dry_run')->default(true);
            $table->string('status', 32)->index('lsir_status_idx');
            $table->json('totals')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_store_import_runs');
    }
};
