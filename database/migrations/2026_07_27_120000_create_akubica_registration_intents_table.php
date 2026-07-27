<?php

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

    public function down(): void
    {
        Schema::dropIfExists('akubica_registration_intents');
    }
};
