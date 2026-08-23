<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_authentication_recovery_contexts', function (Blueprint $table) {
            $table->unsignedBigInteger('recovery_transaction_id')->nullable()->after('recovered_transaction_id');

            $table->index('recovery_transaction_id', 'parc_recovery_transaction_idx');
        });

        Schema::table('payment_authentication_recovery_contexts', function (Blueprint $table) {
            $table->foreign('recovery_transaction_id', 'parc_recovery_tx_fk')
                ->references('id')
                ->on('transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_authentication_recovery_contexts', function (Blueprint $table) {
            $table->dropForeign('parc_recovery_tx_fk');
            $table->dropIndex('parc_recovery_transaction_idx');
            $table->dropColumn('recovery_transaction_id');
        });
    }
};
