<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'payment_provider')) {
                $table->string('payment_provider')->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('transactions', 'provider_order_id')) {
                $table->string('provider_order_id')->nullable()->index()->after('reference_id');
            }
            if (!Schema::hasColumn('transactions', 'provider_transaction_id')) {
                $table->string('provider_transaction_id')->nullable()->after('provider_order_id');
            }
            if (!Schema::hasColumn('transactions', 'payment_status')) {
                $table->string('payment_status')->nullable()->index()->after('gateway_status');
            }
            if (!Schema::hasColumn('transactions', 'raw_response')) {
                $table->json('raw_response')->nullable()->after('gateway_response');
            }
        });

        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->nullable()->index();
            $table->string('provider');
            $table->string('action');
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');

        Schema::table('transactions', function (Blueprint $table) {
            $columns = [
                'payment_provider',
                'provider_order_id',
                'provider_transaction_id',
                'payment_status',
                'raw_response',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
