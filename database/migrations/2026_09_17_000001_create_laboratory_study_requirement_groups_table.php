<?php

use App\Models\LaboratoryTest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_study_requirement_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(LaboratoryTest::class)->constrained()->cascadeOnDelete();
            $table->string('group_key', 120);
            $table->string('operator', 32)->default('all')->index('lsrg_operator_idx');
            $table->boolean('is_required')->default(true);
            $table->string('source', 64)->index('lsrg_source_idx');
            $table->string('confidence', 32)->index('lsrg_confidence_idx');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index('lsrg_is_active_idx');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['laboratory_test_id', 'is_active'], 'lsrg_test_active_idx');
            $table->index(['source', 'confidence'], 'lsrg_source_confidence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_study_requirement_groups');
    }
};
