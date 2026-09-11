<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odessa_reconciliation_items', function (Blueprint $table) {
            $table->string('source_action', 16)->default('NONE')->after('source_row')->index();
            $table->string('source_action_color', 12)->nullable()->after('source_action');
            $table->string('source_action_status', 32)->default('NO_ACTION')->after('source_action_color')->index();
            $table->index(['run_id', 'source_action']);
            $table->index(['run_id', 'source_action_status']);
        });
    }

    public function down(): void
    {
        Schema::table('odessa_reconciliation_items', function (Blueprint $table) {
            $table->dropIndex(['run_id', 'source_action']);
            $table->dropIndex(['run_id', 'source_action_status']);
            $table->dropColumn(['source_action', 'source_action_color', 'source_action_status']);
        });
    }
};
