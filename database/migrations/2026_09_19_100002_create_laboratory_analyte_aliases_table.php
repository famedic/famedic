<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_analyte_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_analyte_id')
                ->constrained('laboratory_analytes')
                ->cascadeOnDelete();
            $table->string('alias_normalized', 191)->unique();
            $table->string('alias_raw')->nullable();
            $table->string('source', 20);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();

            $table->index('laboratory_analyte_id', 'lab_analyte_aliases_analyte_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_analyte_aliases');
    }
};
