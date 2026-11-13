<?php

use App\Models\LaboratoryTest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_test_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(LaboratoryTest::class, 'package_test_id')
                ->unique()
                ->constrained('laboratory_tests')
                ->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('laboratory_test_package_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')
                ->constrained('laboratory_test_packages')
                ->cascadeOnDelete();
            $table->foreignIdFor(LaboratoryTest::class, 'component_test_id')
                ->constrained('laboratory_tests')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();
            $table->unique(['package_id', 'component_test_id'], 'lt_pkg_component_unique');
            $table->index(['package_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_test_package_components');
        Schema::dropIfExists('laboratory_test_packages');
    }
};
