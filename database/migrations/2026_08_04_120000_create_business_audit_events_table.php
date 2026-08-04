<?php

use App\Support\Migrations\MinimumTableContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business audit events (Block 6A — infrastructure only).
 *
 * Append-only. Compatible with MySQL and SQLite. No FK to users / customers /
 * business resources (history must survive soft/hard deletes). No updated_at.
 * No global unique on correlation_id (one logical attempt may emit multiple
 * events in a future instrumentation block).
 *
 * Independent from api_v1_audit_events — do not dual-write.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_audit_events')) {
            MinimumTableContract::assertCompatible('business_audit_events', $this->contract());

            return;
        }

        Schema::create('business_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->timestamp('occurred_at');
            $table->string('event_name', 96);
            $table->string('outcome', 32);
            $table->string('channel', 48);
            $table->string('actor_type', 32);
            $table->string('actor_key', 128);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_customer_id')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_key', 128)->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_key', 128)->nullable();
            $table->string('correlation_id', 128);
            $table->string('error_code', 64)->nullable();
            $table->boolean('retryable')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Index names kept under MySQL's 64-char limit.
            $table->index(['occurred_at'], 'business_audit_events_occurred_at_index');
            $table->index(
                ['event_name', 'occurred_at'],
                'biz_audit_events_event_name_occurred_at_idx'
            );
            $table->index(
                ['channel', 'occurred_at'],
                'biz_audit_events_channel_occurred_at_idx'
            );
            $table->index(
                ['actor_key', 'occurred_at'],
                'biz_audit_events_actor_key_occurred_at_idx'
            );
            $table->index(
                ['actor_customer_id', 'occurred_at'],
                'biz_audit_events_actor_cust_occurred_at_idx'
            );
            $table->index(['correlation_id'], 'business_audit_events_correlation_id_index');
            $table->index(
                ['resource_type', 'resource_key', 'occurred_at'],
                'biz_audit_events_resource_type_key_occ_idx'
            );
            $table->index(
                ['subject_type', 'subject_key', 'occurred_at'],
                'biz_audit_events_subject_type_key_occ_idx'
            );
            $table->index(
                ['outcome', 'occurred_at'],
                'biz_audit_events_outcome_occurred_at_idx'
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
                'public_id' => ['types' => ['string', 'varchar', 'char', 'guid', 'uuid'], 'nullable' => false],
                'occurred_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
                'event_name' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'outcome' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'channel' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'actor_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'actor_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'actor_user_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'actor_customer_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'subject_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'subject_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'resource_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'resource_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'correlation_id' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'error_code' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
                'retryable' => ['types' => ['boolean', 'tinyint', 'integer'], 'nullable' => true],
                'metadata' => ['types' => ['json', 'text', 'clob'], 'nullable' => true],
                'created_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
            ],
            'indexes' => [
                ['name' => 'business_audit_events_public_id_unique', 'columns' => ['public_id'], 'unique' => true],
                ['name' => 'business_audit_events_occurred_at_index', 'columns' => ['occurred_at']],
                [
                    'name' => 'biz_audit_events_event_name_occurred_at_idx',
                    'columns' => ['event_name', 'occurred_at'],
                ],
                [
                    'name' => 'biz_audit_events_channel_occurred_at_idx',
                    'columns' => ['channel', 'occurred_at'],
                ],
                [
                    'name' => 'biz_audit_events_actor_key_occurred_at_idx',
                    'columns' => ['actor_key', 'occurred_at'],
                ],
                [
                    'name' => 'biz_audit_events_actor_cust_occurred_at_idx',
                    'columns' => ['actor_customer_id', 'occurred_at'],
                ],
                ['name' => 'business_audit_events_correlation_id_index', 'columns' => ['correlation_id']],
                [
                    'name' => 'biz_audit_events_resource_type_key_occ_idx',
                    'columns' => ['resource_type', 'resource_key', 'occurred_at'],
                ],
                [
                    'name' => 'biz_audit_events_subject_type_key_occ_idx',
                    'columns' => ['subject_type', 'subject_key', 'occurred_at'],
                ],
                [
                    'name' => 'biz_audit_events_outcome_occurred_at_idx',
                    'columns' => ['outcome', 'occurred_at'],
                ],
            ],
            'foreign_keys' => [],
        ];
    }
};
