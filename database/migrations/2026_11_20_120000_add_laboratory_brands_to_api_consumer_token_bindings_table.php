<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RC2 Batch 2 · A2 (contract rev7 2b6672e6, L06): the token binding grants cart:laboratory:read/write
 * only for the laboratory brands listed here. NULL = no laboratory brand (fail closed). Additive, nullable,
 * no backfill: existing bindings keep exactly their current access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_consumer_token_bindings', function (Blueprint $table) {
            $table->json('laboratory_brands')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('api_consumer_token_bindings', function (Blueprint $table) {
            $table->dropColumn('laboratory_brands');
        });
    }
};
