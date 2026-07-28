<?php

use App\Support\Migrations\MinimumTableContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-A5.3 — Pending secure-registration payload (encrypted) 1:1 with otp_challenges.
 *
 * No soft deletes (ciphertext must be erased on terminal transitions).
 * No UNIQUE on users.phone. No plaintext email/phone columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('akubica_registration_intents')) {
            MinimumTableContract::assertCompatible('akubica_registration_intents', $this->contract());

            return;
        }

        Schema::create('akubica_registration_intents', function (Blueprint $table) {
            $table->id();
            // RESTRICT: no physical otp_challenges purge exists today; CASCADE would
            // silently wipe intent metadata if a future challenge cleanup deleted rows.
            // Metadata retention (ciphertext already null) must outlive accidental deletes.
            $table->foreignId('otp_challenge_id')
                ->unique()
                ->constrained('otp_challenges')
                ->restrictOnDelete();
            $table->string('status', 32);
            $table->text('encrypted_payload')->nullable();
            $table->unsignedTinyInteger('payload_version');
            /** HMAC lookup key (purpose-scoped); never plaintext email. */
            $table->string('email_fingerprint', 64)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('invalidated_at')->nullable()->index();
            $table->string('invalidation_reason', 64)->nullable();
            $table->foreignId('superseded_by_id')
                ->nullable()
                ->constrained('akubica_registration_intents')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'akubica_reg_intents_status_expires_index');
            $table->index(
                ['email_fingerprint', 'status', 'expires_at'],
                'akubica_reg_intents_email_fp_status_expires_index'
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
                'otp_challenge_id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'status' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'encrypted_payload' => ['types' => ['text', 'longtext', 'string'], 'nullable' => true],
                'payload_version' => ['types' => ['tinyint', 'integer', 'smallint'], 'nullable' => false],
                'email_fingerprint' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'expires_at' => ['types' => ['timestamp', 'datetime'], 'nullable' => false],
                'superseded_by_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
            ],
            'indexes' => [
                ['name' => 'akubica_registration_intents_otp_challenge_id_unique', 'columns' => ['otp_challenge_id'], 'unique' => true],
                ['name' => 'akubica_reg_intents_status_expires_index', 'columns' => ['status', 'expires_at']],
                ['name' => 'akubica_reg_intents_email_fp_status_expires_index', 'columns' => ['email_fingerprint', 'status', 'expires_at']],
            ],
            'foreign_keys' => [
                [
                    'columns' => ['otp_challenge_id'],
                    'referenced_table' => 'otp_challenges',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['restrict', 'no action'],
                ],
                [
                    'columns' => ['superseded_by_id'],
                    'referenced_table' => 'akubica_registration_intents',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['set null', 'null'],
                ],
            ],
        ];
    }
};
