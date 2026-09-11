<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique('lc_slug_unique');
            $table->string('name');
            $table->string('category', 80)->nullable()->index('lc_category_idx');
            $table->string('source_column')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index('lc_is_active_idx');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_capabilities');
    }
};
