<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {

            if (!Schema::hasColumn('transactions', 'gateway')) {
                $table->string('gateway')->nullable()->after('payment_method');
            }

            if (!Schema::hasColumn('transactions', 'gateway_transaction_id')) {
                $table->string('gateway_transaction_id')->nullable()->after('gateway');
            }

            if (!Schema::hasColumn('transactions', 'gateway_status')) {
                $table->string('gateway_status')->nullable()->after('gateway_transaction_id');
            }

            if (!Schema::hasColumn('transactions', 'gateway_response')) {
                $table->longText('gateway_response')->nullable()->after('gateway_status');
            }

            if (!Schema::hasColumn('transactions', 'gateway_token')) {
                $table->string('gateway_token')->nullable()->after('gateway_response');
            }

            if (!Schema::hasColumn('transactions', 'gateway_processed_at')) {
                $table->timestamp('gateway_processed_at')->nullable()->after('gateway_token');
            }

            if (!Schema::hasColumn('transactions', 'details')) {
                $table->longText('details')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'gateway',
                'gateway_transaction_id',
                'gateway_status',
                'gateway_response',
                'gateway_token',
                'gateway_processed_at',
            ]);
        });
    }
};
