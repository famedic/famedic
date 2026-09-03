<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_store_import_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_store_import_rows', 'validation_status')) {
                $table->string('validation_status', 32)->nullable()->after('action')->index('lsirr_validation_status_idx');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'invalid_fields')) {
                $table->json('invalid_fields')->nullable()->after('validation_status');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'warnings')) {
                $table->json('warnings')->nullable()->after('invalid_fields');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'evidence')) {
                $table->json('evidence')->nullable()->after('warnings');
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_store_import_rows', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_store_import_rows', 'validation_status')) {
                $table->dropIndex('lsirr_validation_status_idx');
                $table->dropColumn('validation_status');
            }

            foreach (['invalid_fields', 'warnings', 'evidence'] as $column) {
                if (Schema::hasColumn('laboratory_store_import_rows', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
