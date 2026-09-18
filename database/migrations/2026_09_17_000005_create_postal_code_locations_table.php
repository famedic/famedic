<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_code_locations', function (Blueprint $table) {
            $table->id();
            $table->string('postal_code', 5)->unique();
            $table->string('state', 120)->nullable();
            $table->string('municipality', 160)->nullable();
            $table->string('city', 160)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 10, 6);
            $table->string('source', 64);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
            $table->index(['state', 'municipality']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postal_code_locations');
    }
};
