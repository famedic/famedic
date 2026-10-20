<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_laboratory_ai_explanation_consents')) {
            $this->ensureConstraints();

            return;
        }

        Schema::create('customer_laboratory_ai_explanation_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('user_id')->nullable();
            $table->string('status', 30);
            $table->string('consent_version', 40);
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->unique('customer_id', 'claec_customer_uq');
            $table->foreign('customer_id', 'claec_customer_fk')
                ->references('id')
                ->on('customers')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'claec_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_laboratory_ai_explanation_consents');
    }

    private function ensureConstraints(): void
    {
        Schema::table('customer_laboratory_ai_explanation_consents', function (Blueprint $table) {
            if (! $this->indexExists('customer_laboratory_ai_explanation_consents', 'claec_customer_uq')) {
                $table->unique('customer_id', 'claec_customer_uq');
            }

            if (! $this->foreignKeyExists('customer_laboratory_ai_explanation_consents', 'claec_customer_fk')) {
                $table->foreign('customer_id', 'claec_customer_fk')
                    ->references('id')
                    ->on('customers')
                    ->cascadeOnDelete();
            }

            if (! $this->foreignKeyExists('customer_laboratory_ai_explanation_consents', 'claec_user_fk')) {
                $table->foreign('user_id', 'claec_user_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return $connection->select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$database, $table, $indexName],
        ) !== [];
    }

    private function foreignKeyExists(string $table, string $foreignName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return $connection->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$database, $table, $foreignName, 'FOREIGN KEY'],
        ) !== [];
    }
};
