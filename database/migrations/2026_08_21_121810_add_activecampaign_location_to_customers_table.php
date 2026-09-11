<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'ac_location')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->json('ac_location')->nullable()->after('ac_last_sync_at');
            });
        }

        if (! Schema::hasColumn('customers', 'ac_location_cached_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->timestamp('ac_location_cached_at')->nullable()->after('ac_location');
            });
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('customers', 'ac_location_cached_at') ? 'ac_location_cached_at' : null,
                Schema::hasColumn('customers', 'ac_location') ? 'ac_location' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
