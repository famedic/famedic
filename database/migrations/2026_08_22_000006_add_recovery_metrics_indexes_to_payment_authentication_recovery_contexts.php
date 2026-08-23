<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_authentication_recovery_contexts', function (Blueprint $table) {
            $table->index(['context_type', 'started_at'], 'parc_type_started_idx');
            $table->index(['status', 'started_at'], 'parc_status_started_idx');
            $table->index(['recovery_method', 'recovered_at'], 'parc_method_recovered_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_authentication_recovery_contexts', function (Blueprint $table) {
            $table->dropIndex('parc_type_started_idx');
            $table->dropIndex('parc_status_started_idx');
            $table->dropIndex('parc_method_recovered_idx');
        });
    }
};
