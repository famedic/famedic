<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atix_phone_lookup_operations')) {
            return;
        }

        Schema::create('atix_phone_lookup_operations', function (Blueprint $table): void {
            $table->id();
            $table->char('integration_key_hash', 64);
            $table->char('channel_event_hash', 64);
            $table->char('fingerprint', 64);
            $table->char('idempotency_key_hash', 64);
            $table->string('status', 24);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->string('correlation_id', 128);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['integration_key_hash', 'channel_event_hash'], 'atix_phone_lookup_event_unique');
            $table->unique(['integration_key_hash', 'idempotency_key_hash'], 'atix_phone_lookup_idempotency_unique');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }
};
