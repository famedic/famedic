<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('laboratory_purchase_preparation_summaries')) {
            return;
        }

        Schema::create('laboratory_purchase_preparation_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id');
            $table->foreignId('ai_execution_id')->nullable();
            $table->string('source_hash', 64);
            $table->string('status', 40);
            $table->longText('summary_text')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->unique('laboratory_purchase_id', 'lab_purchase_prep_summary_purchase_unique');
            $table->index(['status', 'source_hash'], 'lab_purchase_prep_summary_status_hash_idx');

            $table->foreign('laboratory_purchase_id', 'lpps_purchase_fk')
                ->references('id')
                ->on('laboratory_purchases')
                ->cascadeOnDelete();
            $table->foreign('ai_execution_id', 'lpps_ai_execution_fk')
                ->references('id')
                ->on('ai_executions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_purchase_preparation_summaries');
    }
};
