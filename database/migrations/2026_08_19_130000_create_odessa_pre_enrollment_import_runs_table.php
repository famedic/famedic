<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odessa_pre_enrollment_import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique('opeir_uuid_unique');
            $table->string('source_file_hash', 64)->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_rows')->default(0);
            $table->unsignedInteger('excluded_rows')->default(0);
            $table->unsignedInteger('existing_user_rows')->default(0);
            $table->unsignedInteger('other_email_rows')->default(0);
            $table->unsignedInteger('possible_duplicate_rows')->default(0);
            $table->unsignedInteger('blocked_rows')->default(0);
            $table->string('status', 24)->default('PREVIEWED')->index('opeir_status_idx');
            $table->foreignId('previewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index('opeir_expires_idx');
            $table->string('failure_code', 120)->nullable();
            $table->text('row_hmac_key_encrypted')->nullable();
            $table->timestamps();

            $table->index('source_file_hash', 'opeir_source_file_hash_idx');
        });

        Schema::create('odessa_pre_enrollment_import_run_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained('odessa_pre_enrollment_import_runs')->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 64)->index('opeira_event_idx');
            $table->json('counts_json')->nullable();
            $table->string('result_code', 120)->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('odessa_pre_enrollment_import_run_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained('odessa_pre_enrollment_import_runs')->cascadeOnDelete();
            $table->unsignedInteger('source_row');
            $table->string('diagnostic_status', 64);
            $table->boolean('ready_to_preload')->default(false);
            $table->string('source_row_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['import_run_id', 'source_row'], 'opeirr_run_source_row_unique');
            $table->index(['import_run_id', 'ready_to_preload'], 'opeirr_run_ready_idx');
        });

        Schema::table('odessa_pre_enrollments', function (Blueprint $table) {
            if (! Schema::hasColumn('odessa_pre_enrollments', 'import_run_id')) {
                $table->foreignId('import_run_id')->nullable()->after('id')->constrained('odessa_pre_enrollment_import_runs')->nullOnDelete();
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'source_file_hash')) {
                $table->string('source_file_hash', 64)->nullable()->after('source_run_id');
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'imported_at')) {
                $table->timestamp('imported_at')->nullable()->after('created_by');
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'imported_by')) {
                $table->foreignId('imported_by')->nullable()->after('imported_at')->constrained('users')->nullOnDelete();
            }
            if (Schema::hasColumn('odessa_pre_enrollments', 'source_snapshot_json')) {
                $table->json('source_snapshot_json')->nullable()->change();
            }
        });

        Schema::table('odessa_pre_enrollments', function (Blueprint $table) {
            $table->unique(['source_file_hash', 'source_sheet', 'source_row'], 'ope_source_file_sheet_row_unique');
            $table->index('import_run_id', 'ope_import_run_idx');
        });
    }

    public function down(): void
    {
        Schema::table('odessa_pre_enrollments', function (Blueprint $table) {
            $table->dropUnique('ope_source_file_sheet_row_unique');
            $table->dropIndex('ope_import_run_idx');
        });

        Schema::table('odessa_pre_enrollments', function (Blueprint $table) {
            if (Schema::hasColumn('odessa_pre_enrollments', 'imported_by')) {
                $table->dropConstrainedForeignId('imported_by');
            }
            if (Schema::hasColumn('odessa_pre_enrollments', 'import_run_id')) {
                $table->dropConstrainedForeignId('import_run_id');
            }
            foreach (['source_file_hash', 'imported_at'] as $column) {
                if (Schema::hasColumn('odessa_pre_enrollments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('odessa_pre_enrollment_import_run_rows');
        Schema::dropIfExists('odessa_pre_enrollment_import_run_audits');
        Schema::dropIfExists('odessa_pre_enrollment_import_runs');
    }
};
