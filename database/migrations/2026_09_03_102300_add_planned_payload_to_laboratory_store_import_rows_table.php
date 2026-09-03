<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_store_import_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_store_import_rows', 'planned_payload')) {
                $table->json('planned_payload')->nullable()->after('raw_payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_store_import_rows', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_store_import_rows', 'planned_payload')) {
                $table->dropColumn('planned_payload');
            }
        });
    }
};
