<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atix_sandbox_nonces')) {
            return;
        }

        Schema::create('atix_sandbox_nonces', function (Blueprint $table): void {
            $table->id();
            $table->char('integration_key_hash', 64);
            $table->char('nonce_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['integration_key_hash', 'nonce_hash'], 'atix_sandbox_nonce_unique');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }
};
