<?php

use App\Enums\LaboratoryBrand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('laboratory_stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('brand', array_map(fn($case) => $case->value, LaboratoryBrand::cases()));
            $table->string('state');
            $table->string('address');
            $table->string('weekly_hours');
            $table->string('saturday_hours');
            $table->string('sunday_hours');
            $table->string('google_maps_url');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laboratory_stores');
    }
};
