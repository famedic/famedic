<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_study_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_study_requirement_group_id')
                ->constrained('laboratory_study_requirement_groups', indexName: 'lsr_group_fk')
                ->cascadeOnDelete();
            $table->foreignId('laboratory_capability_id')
                ->nullable()
                ->constrained('laboratory_capabilities', indexName: 'lsr_capability_fk')
                ->nullOnDelete();
            $table->string('capability_slug', 120)->nullable()->index('lsr_capability_slug_idx');
            $table->string('requirement_type', 64)->default('capability')->index('lsr_requirement_type_idx');
            $table->boolean('is_required')->default(true);
            $table->string('source', 64)->index('lsr_source_idx');
            $table->string('confidence', 32)->index('lsr_confidence_idx');
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index('lsr_is_active_idx');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['laboratory_study_requirement_group_id', 'is_active'], 'lsr_group_active_idx');
            $table->index(['laboratory_capability_id', 'is_active'], 'lsr_capability_active_idx');
            $table->index(['source', 'confidence'], 'lsr_source_confidence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_study_requirements');
    }
};
