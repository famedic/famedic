<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'ac_contact_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->unsignedBigInteger('ac_contact_id')->nullable()->index()->after('user_id');
            });
        }

        if (! Schema::hasColumn('customers', 'ac_last_sync_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->timestamp('ac_last_sync_at')->nullable()->after('ac_contact_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('customers', 'ac_last_sync_at') ? 'ac_last_sync_at' : null,
                Schema::hasColumn('customers', 'ac_contact_id') ? 'ac_contact_id' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
