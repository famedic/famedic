<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_order_event_states', function (Blueprint $table) {
            $table->id();
            $table->string('gda_order_id')->unique();
            $table->foreignId('laboratory_purchase_id')->nullable()->constrained('laboratory_purchases')->nullOnDelete();
            $table->unsignedInteger('total_studies')->default(0);
            $table->unsignedInteger('sample_received_count')->default(0);
            $table->unsignedInteger('results_received_count')->default(0);
            $table->timestamp('sample_email_sent_at')->nullable();
            $table->timestamp('results_email_sent_at')->nullable();
            $table->timestamp('sample_tag_sent_at')->nullable();
            $table->timestamp('results_tag_sent_at')->nullable();
            $table->timestamp('first_event_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lab_order_event_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_event_state_id')->constrained('lab_order_event_states')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('study_external_id')->nullable();
            $table->string('provider_event_id')->nullable();
            $table->string('payload_hash', 64);
            $table->timestamps();

            $table->unique(
                ['lab_order_event_state_id', 'event_type', 'study_external_id'],
                'lab_evt_receipt_state_type_study_unique'
            );
            $table->unique('provider_event_id', 'lab_evt_receipt_provider_event_unique');
            $table->unique(
                ['lab_order_event_state_id', 'event_type', 'payload_hash'],
                'lab_evt_receipt_state_type_hash_unique'
            );

            $table->index(['lab_order_event_state_id', 'event_type'], 'lab_evt_receipt_state_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_order_event_receipts');
        Schema::dropIfExists('lab_order_event_states');
    }
};
