<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_attempts')) {
            return;
        }

        Schema::table('payment_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_attempts', 'idempotency_key')) {
                $table->string('idempotency_key', 128)->nullable()->after('reference');
            }
        });

        $this->ensureIndex('payment_attempts', ['gateway', 'reference'], 'payment_attempts_gateway_reference_index');
        $this->ensureIndex('payment_attempts', ['customer_id', 'gateway', 'status'], 'payment_attempts_customer_gateway_status_index');
        $this->ensureIndex('payment_attempts', 'processor_transaction_id', 'payment_attempts_processor_transaction_id_index');
        $this->ensureIndex('payment_attempts', 'created_at', 'payment_attempts_created_at_index');
        $this->ensureIndex('payment_attempts', ['gateway', 'idempotency_key'], 'payment_attempts_gateway_idempotency_key_index');
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_attempts')) {
            return;
        }

        $this->dropIndexIfExists('payment_attempts', 'payment_attempts_gateway_reference_index');
        $this->dropIndexIfExists('payment_attempts', 'payment_attempts_customer_gateway_status_index');
        $this->dropIndexIfExists('payment_attempts', 'payment_attempts_processor_transaction_id_index');
        $this->dropIndexIfExists('payment_attempts', 'payment_attempts_created_at_index');
        $this->dropIndexIfExists('payment_attempts', 'payment_attempts_gateway_idempotency_key_index');

        if (Schema::hasColumn('payment_attempts', 'idempotency_key')) {
            Schema::table('payment_attempts', function (Blueprint $table) {
                $table->dropColumn('idempotency_key');
            });
        }
    }

    private function ensureIndex(string $table, string|array $columns, string $indexName): void
    {
        if (Schema::hasIndex($table, $indexName) || Schema::hasIndex($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }
};
