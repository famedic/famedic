<?php

use App\Support\Migrations\MinimumTableContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otp_delivery_operations')) {
            MinimumTableContract::assertCompatible('otp_delivery_operations', $this->contract());

            return;
        }

        Schema::create('otp_delivery_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key', 64)->unique();
            $table->foreignId('otp_challenge_id')->nullable()->index()->constrained('otp_challenges')->nullOnDelete();
            $table->string('purpose');
            $table->string('status');
            $table->string('primary_channel');
            $table->boolean('fallback_used')->default(false);
            $table->string('provider_alias')->nullable();
            $table->string('result_class')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->uuid('correlation_id');
            $table->timestamps();
        });
    }

    /**
     * Conservative no-op rollback under schema drift.
     *
     * up() may accept a pre-existing compatible table without creating it.
     * Without a durable authorship record, dropIfExists would destroy that
     * table and its data on migrate:rollback. The table is therefore retained.
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
                'operation_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'otp_challenge_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'purpose' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'status' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'primary_channel' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'fallback_used' => ['types' => ['boolean', 'tinyint', 'integer'], 'nullable' => false],
                'attempt_count' => ['types' => ['tinyint', 'integer', 'smallint'], 'nullable' => false],
                'correlation_id' => ['types' => ['uuid', 'string', 'varchar', 'char'], 'nullable' => false],
            ],
            'indexes' => [
                ['name' => 'otp_delivery_operations_operation_key_unique', 'columns' => ['operation_key'], 'unique' => true],
                ['name' => 'otp_delivery_operations_otp_challenge_id_index', 'columns' => ['otp_challenge_id']],
            ],
            'foreign_keys' => [
                [
                    'columns' => ['otp_challenge_id'],
                    'referenced_table' => 'otp_challenges',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['set null', 'null'],
                ],
            ],
        ];
    }
};
