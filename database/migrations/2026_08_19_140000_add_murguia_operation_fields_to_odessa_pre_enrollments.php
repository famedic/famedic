<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odessa_pre_enrollments', function (Blueprint $table) {
            if (! Schema::hasColumn('odessa_pre_enrollments', 'murguia_correlation_id')) {
                $table->uuid('murguia_correlation_id')->nullable()->after('murguia_synced_at');
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'murguia_operation_token')) {
                $table->uuid('murguia_operation_token')->nullable()->after('murguia_correlation_id');
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'murguia_attempts')) {
                $table->unsignedSmallInteger('murguia_attempts')->default(0)->after('murguia_operation_token');
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'murguia_pending_since')) {
                $table->timestamp('murguia_pending_since')->nullable()->after('murguia_attempts');
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'murguia_registration_acknowledged_at')) {
                $table->timestamp('murguia_registration_acknowledged_at')->nullable()->after('murguia_pending_since');
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'murguia_checked_at')) {
                $table->timestamp('murguia_checked_at')->nullable()->after('murguia_registration_acknowledged_at');
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'murguia_last_http_status')) {
                $table->unsignedSmallInteger('murguia_last_http_status')->nullable()->after('murguia_checked_at');
            }
            if (! Schema::hasColumn('odessa_pre_enrollments', 'murguia_last_event_code')) {
                $table->string('murguia_last_event_code', 64)->nullable()->after('murguia_last_http_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('odessa_pre_enrollments', function (Blueprint $table) {
            $table->dropColumn([
                'murguia_correlation_id',
                'murguia_operation_token',
                'murguia_attempts',
                'murguia_pending_since',
                'murguia_registration_acknowledged_at',
                'murguia_checked_at',
                'murguia_last_http_status',
                'murguia_last_event_code',
            ]);
        });
    }
};
