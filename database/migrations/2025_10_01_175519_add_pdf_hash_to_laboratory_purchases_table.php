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
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            $table->string('pdf_hash', 12)->nullable()->after('results');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            $table->dropColumn('pdf_hash');
        });
    }
};
