<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_store_import_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_store_import_rows', 'resolution_source')) {
                $table->string('resolution_source', 32)->nullable()->after('action')->index('lsirr_resolution_source_idx');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'resolution_decision')) {
                $table->string('resolution_decision', 32)->nullable()->after('resolution_source');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'manual_resolution_id')) {
                $table->foreignId('manual_resolution_id')->nullable()->after('resolution_decision')->constrained('laboratory_store_import_resolutions')->nullOnDelete();
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'auto_classification')) {
                $table->string('auto_classification', 32)->nullable()->after('manual_resolution_id');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'auto_action')) {
                $table->string('auto_action', 32)->nullable()->after('auto_classification');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'auto_matched_store_id')) {
                $table->foreignId('auto_matched_store_id')->nullable()->after('auto_action')->constrained('laboratory_stores')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_store_import_rows', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_store_import_rows', 'manual_resolution_id')) {
                $table->dropConstrainedForeignId('manual_resolution_id');
            }

            if (Schema::hasColumn('laboratory_store_import_rows', 'auto_matched_store_id')) {
                $table->dropConstrainedForeignId('auto_matched_store_id');
            }

            if (Schema::hasColumn('laboratory_store_import_rows', 'resolution_source')) {
                $table->dropIndex('lsirr_resolution_source_idx');
            }

            foreach (['resolution_source', 'resolution_decision', 'auto_classification', 'auto_action'] as $column) {
                if (Schema::hasColumn('laboratory_store_import_rows', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
