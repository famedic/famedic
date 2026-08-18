<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odessa_reconciliation_items', function (Blueprint $table) {
            $table->string('resolution_status', 24)->default('UNRESOLVED')->index()->after('reviewed_at');
            $table->json('resolved_flags_json')->nullable()->after('resolution_status');
        });
    }

    public function down(): void
    {
        Schema::table('odessa_reconciliation_items', function (Blueprint $table) {
            $table->dropColumn(['resolution_status', 'resolved_flags_json']);
        });
    }
};
