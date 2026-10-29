<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_staging_diagnostic_vaults', function (Blueprint $table) {
            $table->id();
            $table->uuid('challenge_public_id')->unique();
            $table->string('purpose', 64);
            $table->text('code_ciphertext');
            $table->timestamp('expires_at');
            $table->timestamp('revealed_at')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'revealed_at']);
        });

        Schema::create('otp_staging_diagnostic_reveal_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('administrator_id')->constrained('administrators')->cascadeOnDelete();
            $table->uuid('challenge_public_id');
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('challenge_public_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_staging_diagnostic_reveal_audits');
        Schema::dropIfExists('otp_staging_diagnostic_vaults');
    }
};
