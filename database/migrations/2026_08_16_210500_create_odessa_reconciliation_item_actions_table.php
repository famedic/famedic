<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odessa_reconciliation_item_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('odessa_reconciliation_items')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('odessa_reconciliation_runs')->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_type')->index();
            $table->string('status', 24)->index();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('request_json')->nullable();
            $table->json('result_json')->nullable();
            $table->text('reason')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'action_type']);
            $table->index(['item_id', 'status']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odessa_reconciliation_item_actions');
    }
};
