<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['api_consumers', 'api_consumer_channels'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('revoked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['api_consumer_channels', 'api_consumers'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('revoked_at'));
        }
    }
};
