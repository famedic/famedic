<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_store_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_store_id')->constrained()->cascadeOnDelete();
            $table->string('service_type', 64);
            $table->string('name')->nullable();
            $table->text('schedule_raw')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source', 64)->default('gda');
            $table->timestamps();

            $table->index(['laboratory_store_id', 'service_type', 'is_active'], 'lss_store_type_active_idx');
            $table->index('service_type', 'lss_service_type_idx');
            $table->index('source', 'lss_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_store_services');
    }
};
