<?php

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
        Schema::table('laboratory_quotes', function (Blueprint $table) {
            // Si los campos no existen, agregarlos
            if (!Schema::hasColumn('laboratory_quotes', 'contact_id')) {
                $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            }
            
            if (!Schema::hasColumn('laboratory_quotes', 'address_id')) {
                $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_quotes', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['address_id']);
            $table->dropColumn(['contact_id', 'address_id']);
        });
    }
};
