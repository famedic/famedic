<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_store_capability', function (Blueprint $table) {
            $table->foreignId('laboratory_store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('laboratory_capability_id')->constrained('laboratory_capabilities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['laboratory_store_id', 'laboratory_capability_id'], 'lsc_store_capability_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_store_capability');
    }
};
