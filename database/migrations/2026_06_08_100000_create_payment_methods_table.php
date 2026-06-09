<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                $table->string('provider', 32);
                $table->text('provider_token');

                $table->string('brand', 32)->nullable();
                $table->string('last4', 4)->nullable();
                $table->string('exp_month', 2)->nullable();
                $table->string('exp_year', 4)->nullable();

                $table->string('affiliation_id', 32)->nullable();
                $table->string('media_id', 32)->nullable();

                $table->string('status', 32)->default('active');

                $table->string('alias')->nullable();
                $table->string('card_holder')->nullable();

                // FK a payment_transactions se agrega en la migración de payment_transactions
                // (dependencia circular: tokenización crea TX antes que PaymentMethod).
                $table->unsignedBigInteger('created_from_transaction_id')->nullable();

                $table->timestamp('last_used_at')->nullable();

                $table->timestamps();

                $table->index('provider', 'payment_methods_provider_index');
                $table->index('status', 'payment_methods_status_index');
                $table->index(['user_id', 'provider', 'status'], 'payment_methods_user_id_provider_status_index');
                $table->index('created_from_transaction_id', 'payment_methods_created_from_transaction_id_index');
            });

            return;
        }

        $this->ensureIndex('payment_methods', 'provider', 'payment_methods_provider_index');
        $this->ensureIndex('payment_methods', 'status', 'payment_methods_status_index');
        $this->ensureIndex('payment_methods', 'created_from_transaction_id', 'payment_methods_created_from_transaction_id_index');
        $this->ensureIndex('payment_methods', ['user_id', 'provider', 'status'], 'payment_methods_user_id_provider_status_index');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
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
};
