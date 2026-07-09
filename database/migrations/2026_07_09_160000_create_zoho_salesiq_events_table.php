<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zoho_salesiq_events')) {
            return;
        }

        Schema::create('zoho_salesiq_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('visitor_id', 191)->nullable();
            $table->string('conversation_id', 191)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('operator_name', 191)->nullable();
            $table->string('department', 191)->nullable();
            $table->string('intent', 64)->nullable();
            $table->string('last_event', 128)->nullable();
            $table->string('page', 500)->nullable();
            $table->string('environment', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index('event_type');
            $table->index('visitor_id');
            $table->index('conversation_id');
            $table->index('user_id');
            $table->index('customer_id');
            $table->index('intent');
            $table->index('occurred_at');
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_salesiq_events');
    }
};
