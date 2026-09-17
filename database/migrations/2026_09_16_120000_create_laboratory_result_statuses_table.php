<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_result_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')
                ->constrained('laboratory_purchases')
                ->cascadeOnDelete();
            $table->foreignId('laboratory_purchase_item_id')
                ->constrained('laboratory_purchase_items')
                ->cascadeOnDelete();
            $table->string('status', 40)->default('not_available');
            $table->timestamp('first_available_at')->nullable();
            $table->timestamp('interpreted_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedInteger('check_attempts')->default(0);
            $table->timestamp('completed_notified_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['laboratory_purchase_id', 'laboratory_purchase_item_id'],
                'lab_result_status_purchase_item_unique'
            );
            $table->index(['status', 'next_check_at'], 'lab_result_status_next_check_idx');
            $table->index('first_available_at', 'lab_result_status_first_available_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_statuses');
    }
};
