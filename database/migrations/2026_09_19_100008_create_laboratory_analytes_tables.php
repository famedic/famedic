<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laboratory_analytes')) {
            Schema::create('laboratory_analytes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 120)->unique();
                $table->string('canonical_name');
                $table->string('loinc_code', 40)->nullable()->unique();
                $table->string('default_unit', 40)->nullable();
                $table->string('value_kind', 20);
                $table->string('category', 80)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('laboratory_analyte_aliases')) {
            Schema::create('laboratory_analyte_aliases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('laboratory_analyte_id')->constrained('laboratory_analytes')->cascadeOnDelete();
                $table->string('alias_normalized', 191)->unique();
                $table->string('alias_raw')->nullable();
                $table->string('source', 20);
                $table->decimal('confidence', 5, 4)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_analyte_aliases');
        Schema::dropIfExists('laboratory_analytes');
    }
};
