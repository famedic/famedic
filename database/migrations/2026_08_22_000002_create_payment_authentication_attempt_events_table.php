<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_authentication_attempts', function (Blueprint $table) {
            $table->unsignedInteger('external_call_count')->default(0)->after('duplicate_request_count');
            $table->unsignedInteger('provider_link_call_count')->default(0)->after('external_call_count');
            $table->unsignedInteger('status_poll_call_count')->default(0)->after('provider_link_call_count');
            $table->unsignedInteger('tokenization_call_count')->default(0)->after('status_poll_call_count');
        });

        Schema::create('payment_authentication_attempt_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->unsignedBigInteger('payment_authentication_attempt_id');
            $table->foreign('payment_authentication_attempt_id', 'paae_attempt_fk')
                ->references('id')
                ->on('payment_authentication_attempts')
                ->restrictOnDelete();
            $table->string('event_type', 100);
            $table->string('source', 40);
            $table->string('status_from', 80)->nullable();
            $table->string('status_to', 80)->nullable();
            $table->string('result_category', 80)->nullable();
            $table->string('failure_origin', 80)->nullable();
            $table->string('failure_certainty', 40)->nullable();
            $table->string('provider_status', 120)->nullable();
            $table->string('provider_code', 80)->nullable();
            $table->string('provider_message', 500)->nullable();
            $table->string('external_operation', 100)->nullable();
            $table->unsignedInteger('external_call_number')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('dedupe_key', 160)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['payment_authentication_attempt_id', 'dedupe_key'], 'paae_attempt_dedupe_unique');
            $table->index(['payment_authentication_attempt_id', 'occurred_at'], 'paae_attempt_occurred_idx');
            $table->index(['event_type', 'occurred_at'], 'paae_event_occurred_idx');
            $table->index(['result_category', 'occurred_at'], 'paae_category_occurred_idx');
            $table->index(['source', 'occurred_at'], 'paae_source_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_authentication_attempt_events');

        Schema::table('payment_authentication_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'external_call_count',
                'provider_link_call_count',
                'status_poll_call_count',
                'tokenization_call_count',
            ]);
        });
    }
};
