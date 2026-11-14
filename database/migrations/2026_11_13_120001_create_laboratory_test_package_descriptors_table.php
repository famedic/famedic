<?php

use App\Models\LaboratoryTestPackage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_test_package_descriptors', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(LaboratoryTestPackage::class, 'package_id')
                ->constrained('laboratory_test_packages')
                ->cascadeOnDelete();
            $table->string('description', 255);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->enum('validation_status', [
                'pending_commercial_validation',
                'validated',
                'rejected',
            ])->default('pending_commercial_validation');
            $table->string('validation_reference', 255)->nullable();
            $table->timestamps();
            $table->unique(['package_id', 'description'], 'lt_pkg_descriptor_unique');
            $table->index(['package_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_test_package_descriptors');
    }
};
