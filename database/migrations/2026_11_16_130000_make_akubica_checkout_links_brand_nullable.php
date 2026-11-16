<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RC2 B24 (D2): medical continuity links have no laboratory brand. Additive: existing rows keep their brand.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('akubica_checkout_links', function (Blueprint $table) {
            $table->string('laboratory_brand', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Brandless rows are short-lived (<=10 min) single-use medical continuity links; they cannot survive NOT NULL.
        DB::table('akubica_checkout_links')->whereNull('laboratory_brand')->delete();

        Schema::table('akubica_checkout_links', function (Blueprint $table) {
            $table->string('laboratory_brand', 32)->nullable(false)->change();
        });
    }
};
