<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_store_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_store_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week')->comment('ISO-8601 convention: 1=Monday through 7=Sunday.');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->text('raw_text')->nullable();
            $table->string('source', 64)->default('gda');
            $table->timestamps();

            $table->unique(['laboratory_store_id', 'day_of_week', 'source'], 'lsh_store_day_source_unique');
            $table->index(['day_of_week', 'is_closed'], 'lsh_day_closed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_store_hours');
    }
};
