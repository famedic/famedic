<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_store_import_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_store_import_rows', 'source_store_snapshot')) {
                $table->json('source_store_snapshot')->nullable()->after('auto_matched_store_id');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'apply_status')) {
                $table->string('apply_status', 32)->nullable()->after('source_store_snapshot')->index('lsirr_apply_status_idx');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'applied_action')) {
                $table->string('applied_action', 32)->nullable()->after('apply_status');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'applied_store_id')) {
                $table->foreignId('applied_store_id')->nullable()->after('applied_action')->constrained('laboratory_stores')->nullOnDelete();
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'before_snapshot')) {
                $table->json('before_snapshot')->nullable()->after('applied_store_id');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'after_snapshot')) {
                $table->json('after_snapshot')->nullable()->after('before_snapshot');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'apply_error')) {
                $table->text('apply_error')->nullable()->after('after_snapshot');
            }

            if (! Schema::hasColumn('laboratory_store_import_rows', 'applied_at')) {
                $table->timestamp('applied_at')->nullable()->after('apply_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_store_import_rows', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_store_import_rows', 'applied_store_id')) {
                $table->dropConstrainedForeignId('applied_store_id');
            }

            if (Schema::hasColumn('laboratory_store_import_rows', 'apply_status')) {
                $table->dropIndex('lsirr_apply_status_idx');
            }

            foreach (['source_store_snapshot', 'apply_status', 'applied_action', 'before_snapshot', 'after_snapshot', 'apply_error', 'applied_at'] as $column) {
                if (Schema::hasColumn('laboratory_store_import_rows', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
