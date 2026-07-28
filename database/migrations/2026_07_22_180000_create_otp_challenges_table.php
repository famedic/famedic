<?php

use App\Support\Migrations\MinimumTableContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otp_challenges')) {
            MinimumTableContract::assertCompatible('otp_challenges', $this->contract());

            return;
        }

        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_key', 191)->nullable();
            $table->string('purpose', 64)->index();
            $table->string('channel', 16);
            $table->string('destination_normalized', 191)->nullable();
            $table->string('destination_masked', 64)->nullable();
            $table->string('code_hash', 255);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('invalidated_at')->nullable()->index();
            $table->string('invalidated_reason', 64)->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->unsignedTinyInteger('send_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('context_type', 64)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'expires_at'], 'otp_challenges_user_purpose_expires_index');
            $table->index(
                ['subject_type', 'subject_key', 'purpose', 'expires_at'],
                'otp_challenges_subject_purpose_expires_index'
            );
            $table->index(
                ['purpose', 'context_type', 'context_id', 'expires_at'],
                'otp_challenges_purpose_context_expires_index'
            );
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
                'public_id' => ['types' => ['uuid', 'string', 'varchar', 'char'], 'nullable' => false],
                'user_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                'purpose' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'channel' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'code_hash' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'expires_at' => ['types' => ['timestamp', 'datetime'], 'nullable' => false],
                'failed_attempts' => ['types' => ['tinyint', 'integer', 'smallint'], 'nullable' => false],
                'max_attempts' => ['types' => ['tinyint', 'integer', 'smallint'], 'nullable' => false],
            ],
            'indexes' => [
                ['name' => 'otp_challenges_public_id_unique', 'columns' => ['public_id'], 'unique' => true],
                ['name' => 'otp_challenges_user_purpose_expires_index', 'columns' => ['user_id', 'purpose', 'expires_at']],
                ['name' => 'otp_challenges_subject_purpose_expires_index', 'columns' => ['subject_type', 'subject_key', 'purpose', 'expires_at']],
                ['name' => 'otp_challenges_purpose_context_expires_index', 'columns' => ['purpose', 'context_type', 'context_id', 'expires_at']],
            ],
            'foreign_keys' => [
                [
                    'columns' => ['user_id'],
                    'referenced_table' => 'users',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['set null', 'null'],
                ],
            ],
        ];
    }
};
