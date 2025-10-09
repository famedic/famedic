<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTestCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_tests', function (Blueprint $table) {
            $table->id();
            $table->enum('brand', array_map(fn($case) => $case->value, LaboratoryBrand::cases()));
            $table->string('gda_id')->unique();
            $table->string('name');
            $table->mediumText('indications');
            $table->boolean('requires_appointment')->default(false);
            $table->unsignedInteger('public_price_cents');
            $table->unsignedInteger('famedic_price_cents');
            $table->foreignIdFor(LaboratoryTestCategory::class)->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_tests');
    }
};
