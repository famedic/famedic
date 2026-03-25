<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('murguia_sync_logs', function (Blueprint $table) {
            $table->foreignId('triggered_by')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            $table->string('entry_type', 16)->default('bulk')->after('triggered_by');
        });
    }

    public function down(): void
    {
        Schema::table('murguia_sync_logs', function (Blueprint $table) {
            $table->dropForeign(['triggered_by']);
            $table->dropColumn(['triggered_by', 'entry_type']);
        });
    }
};
