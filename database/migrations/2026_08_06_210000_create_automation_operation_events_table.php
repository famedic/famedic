<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_operation_events', function (Blueprint $table) {
            $table->id();
            $table->string('automation', 64); // PaymentAutomation | OrderAutomation | Dispatcher | Diagnostic
            $table->string('driver', 128)->nullable();
            $table->string('channel', 64)->nullable(); // laboratory | pharmacy | membership | payment
            $table->string('operation', 128)->nullable();
            $table->string('result', 32); // success | failed | skipped | partial
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('retryable')->nullable();
            $table->foreignId('customer_id')->nullable()->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('reference')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['automation', 'occurred_at']);
            $table->index(['driver', 'occurred_at']);
            $table->index(['result', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_operation_events');
    }
};
