<?php

use App\Support\Migrations\MinimumTableContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API V1 append-only audit events (phase 1 — infrastructure).
 *
 * Compatible with MySQL and SQLite. No FK to users / customers / tokens /
 * idempotency records. No updated_at. No global unique on correlation_id
 * (one request may emit multiple legitimate events).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_v1_audit_events')) {
            MinimumTableContract::assertCompatible('api_v1_audit_events', $this->contract());

            return;
        }

        Schema::create('api_v1_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_name', 96);
            $table->timestamp('occurred_at');
            $table->string('correlation_id', 128);
            $table->string('related_correlation_id', 128)->nullable();
            $table->string('actor_type', 32);
            $table->string('actor_key', 128);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_key', 128)->nullable();
            $table->string('route_name', 128)->nullable();
            $table->string('method', 10);
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->boolean('retryable')->nullable();
            $table->unsignedBigInteger('idempotency_record_id')->nullable();
            $table->string('idempotency_effect', 24)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Index names kept under MySQL's 64-char limit.
            $table->index(['occurred_at'], 'api_v1_audit_events_occurred_at_index');
            $table->index(
                ['event_name', 'occurred_at'],
                'api_v1_audit_events_event_name_occurred_at_index'
            );
            $table->index(
                ['customer_id', 'occurred_at'],
                'api_v1_audit_events_customer_id_occurred_at_index'
            );
            $table->index(['correlation_id'], 'api_v1_audit_events_correlation_id_index');
            $table->index(
                ['resource_type', 'resource_key', 'occurred_at'],
                'api_v1_audit_events_resource_type_key_occurred_at_index'
            );
            $table->index(
                ['actor_key', 'occurred_at'],
                'api_v1_audit_events_actor_key_occurred_at_index'
            );
        });
    }

    /**
     * Conservative no-op rollback under schema drift (Akubica OTP pattern).
     */
    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }

    /**
     * @return array{
     *     columns: array<string, array{types: list<string>, nullable?: bool|null}>,
     *     indexes: list<array{name: string, columns: list<string>, unique?: bool}>,
     *     foreign_keys: list<array{columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_delete?: string|list<string>}>
     * }
     */
    private function contract(): array
    {
        return [
            'columns' => [
                'id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'event_name' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'occurred_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
                'correlation_id' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'related_correlation_id' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'actor_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'actor_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'customer_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'user_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'personal_access_token_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'resource_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'resource_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'route_name' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'method' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'outcome' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'http_status' => ['types' => ['integer', 'smallint', 'bigint'], 'nullable' => true],
                'error_code' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'retryable' => ['types' => ['boolean', 'tinyint', 'integer'], 'nullable' => true],
                'idempotency_record_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'idempotency_effect' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'ip_hash' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'user_agent_hash' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'metadata' => ['types' => ['json', 'text', 'clob'], 'nullable' => true],
                'created_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
            ],
            'indexes' => [
                ['name' => 'api_v1_audit_events_occurred_at_index', 'columns' => ['occurred_at']],
                [
                    'name' => 'api_v1_audit_events_event_name_occurred_at_index',
                    'columns' => ['event_name', 'occurred_at'],
                ],
                [
                    'name' => 'api_v1_audit_events_customer_id_occurred_at_index',
                    'columns' => ['customer_id', 'occurred_at'],
                ],
                ['name' => 'api_v1_audit_events_correlation_id_index', 'columns' => ['correlation_id']],
                [
                    'name' => 'api_v1_audit_events_resource_type_key_occurred_at_index',
                    'columns' => ['resource_type', 'resource_key', 'occurred_at'],
                ],
                [
                    'name' => 'api_v1_audit_events_actor_key_occurred_at_index',
                    'columns' => ['actor_key', 'occurred_at'],
                ],
            ],
            'foreign_keys' => [],
        ];
    }
};
