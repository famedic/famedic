<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('murguia_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('medical_attention_identifier')->nullable();
            $table->string('action', 32);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['action', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('murguia_sync_logs');
    }
};
