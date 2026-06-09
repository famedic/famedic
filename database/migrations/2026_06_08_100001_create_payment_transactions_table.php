<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            Schema::create('payment_transactions', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();

                $table->nullableMorphs('related');

                $table->string('provider', 32);
                $table->string('flow', 64)->nullable();

                $table->string('folio', 12)->nullable();
                $table->string('reference', 128)->nullable();
                $table->string('previous_reference', 128)->nullable();
                $table->string('auth_code', 64)->nullable();

                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 3)->default('MXN');
                $table->string('mode', 8)->nullable();

                $table->string('status', 32)->nullable();

                $table->string('bnrg_codigo_proc', 8)->nullable();
                $table->string('bnrg_codigo_proc_trans', 8)->nullable();
                $table->string('bnrg_codigo_rechazo', 16)->nullable();
                $table->string('bnrg_codigo_emisor', 16)->nullable();
                $table->text('bnrg_texto')->nullable();
                $table->string('bnrg_estado_trans', 8)->nullable();
                $table->string('bnrg_tipo_trans', 8)->nullable();

                $table->json('raw_request')->nullable();
                $table->json('raw_response_headers')->nullable();

                $table->timestamps();

                $table->index(['provider', 'reference'], 'payment_transactions_provider_reference_index');
                $table->index(['provider', 'folio'], 'payment_transactions_provider_folio_index');
                $table->index(['flow', 'status'], 'payment_transactions_flow_status_index');
            });

            $this->ensureCreatedFromTransactionForeignKey();

            return;
        }

        $this->ensureIndex('payment_transactions', ['provider', 'reference'], 'payment_transactions_provider_reference_index');
        $this->ensureIndex('payment_transactions', ['provider', 'folio'], 'payment_transactions_provider_folio_index');
        $this->ensureIndex('payment_transactions', ['flow', 'status'], 'payment_transactions_flow_status_index');
        $this->ensureCreatedFromTransactionForeignKey();
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_methods') && $this->foreignKeyExists('payment_methods', 'created_from_transaction_id')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropForeign(['created_from_transaction_id']);
            });
        }

        Schema::dropIfExists('payment_transactions');
    }

    private function ensureCreatedFromTransactionForeignKey(): void
    {
        if (! Schema::hasTable('payment_methods') || ! Schema::hasTable('payment_transactions')) {
            return;
        }

        if ($this->foreignKeyExists('payment_methods', 'created_from_transaction_id')) {
            return;
        }

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreign('created_from_transaction_id')
                ->references('id')
                ->on('payment_transactions')
                ->nullOnDelete();
        });
    }

    private function ensureIndex(string $table, array $columns, string $indexName): void
    {
        if (Schema::hasIndex($table, $indexName) || Schema::hasIndex($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $foreignKeys = DB::select("PRAGMA foreign_key_list({$table})");

            foreach ($foreignKeys as $foreignKey) {
                if (($foreignKey->from ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();

        $result = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
             AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table, $column]
        );

        return (int) ($result->aggregate ?? 0) > 0;
    }
};
