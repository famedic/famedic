<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activecampaign_dispatches')) {
            return;
        }

        Schema::create('activecampaign_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('related_entity_type', 64)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('email')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'status']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('user_id');
            $table->index('customer_id');
            $table->index('email');
            $table->index('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activecampaign_dispatches');
    }
};
