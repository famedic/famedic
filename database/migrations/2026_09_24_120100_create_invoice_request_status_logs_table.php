<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_request_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_request_id')
                ->constrained('invoice_requests')
                ->cascadeOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->string('trigger', 50);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['invoice_request_id', 'created_at'],
                'invoice_request_status_logs_request_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_request_status_logs');
    }
};
