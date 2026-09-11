<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odessa_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('odessa_reconciliation_runs')->cascadeOnDelete();
            $table->string('canonical_id')->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('company_excel')->nullable();
            $table->string('employee_excel')->nullable();
            $table->string('odessa_id_excel')->nullable();
            $table->string('name_excel')->nullable();
            $table->date('birth_date_excel')->nullable();
            $table->string('email_excel')->nullable();
            $table->string('match_type')->index();
            $table->string('match_confidence')->nullable();
            $table->string('identity_status')->nullable()->index();
            $table->string('account_status')->nullable()->index();
            $table->string('membership_status')->nullable()->index();
            $table->string('murguia_status')->nullable()->index();
            $table->string('primary_status')->index();
            $table->json('data_quality_flags_json')->nullable();
            $table->string('audit_reason')->nullable()->index();
            $table->json('review_notes_json')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('odessa_account_id')->nullable();
            $table->string('odessa_id_db')->nullable();
            $table->string('company_external_db')->nullable();
            $table->string('partner_db')->nullable();
            $table->string('name_db')->nullable();
            $table->date('birth_date_db')->nullable();
            $table->string('email_db')->nullable();
            $table->string('medical_attention_identifier')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->timestamp('subscription_start_date')->nullable();
            $table->timestamp('subscription_end_date')->nullable();
            $table->string('subscription_status')->nullable()->index();
            $table->timestamp('last_murguia_sync_at')->nullable();
            $table->json('evidence_json')->nullable();
            $table->json('snapshot_json')->nullable();
            $table->string('review_status', 24)->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'review_status']);
            $table->index(['run_id', 'primary_status']);
            $table->index(['run_id', 'murguia_status']);
            $table->index(['run_id', 'membership_status']);
            $table->index(['run_id', 'match_type']);
            $table->index('user_id');
            $table->index('customer_id');
            $table->index('odessa_id_excel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odessa_reconciliation_items');
    }
};
