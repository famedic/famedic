<?php

use App\Support\Migrations\MinimumTableContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API V1 HTTP idempotency records (phase 1).
 *
 * Compatible with MySQL and SQLite. No FK to users / personal_access_tokens.
 * response_body is stored encrypted at the Eloquent layer (APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_v1_idempotency_records')) {
            MinimumTableContract::assertCompatible('api_v1_idempotency_records', $this->contract());

            return;
        }

        Schema::create('api_v1_idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_key', 128);
            $table->string('method', 10);
            $table->string('path', 191);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('status', 24);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->string('correlation_id', 128);
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(
                ['actor_key', 'method', 'path', 'key_hash'],
                'api_v1_idempotency_actor_method_path_key_unique'
            );
            $table->index(['expires_at'], 'api_v1_idempotency_expires_at_index');
            $table->index(
                ['status', 'lease_expires_at'],
                'api_v1_idempotency_status_lease_index'
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
                'actor_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'method' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'path' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'key_hash' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'request_hash' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'status' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'correlation_id' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'expires_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
            ],
            'indexes' => [
                [
                    'name' => 'api_v1_idempotency_actor_method_path_key_unique',
                    'columns' => ['actor_key', 'method', 'path', 'key_hash'],
                    'unique' => true,
                ],
                ['name' => 'api_v1_idempotency_expires_at_index', 'columns' => ['expires_at']],
                ['name' => 'api_v1_idempotency_status_lease_index', 'columns' => ['status', 'lease_expires_at']],
            ],
            'foreign_keys' => [],
        ];
    }
};
