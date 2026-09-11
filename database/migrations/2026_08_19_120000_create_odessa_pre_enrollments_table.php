<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odessa_pre_enrollments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique('ope_uuid_unique');
            $table->unsignedBigInteger('source_run_id')->nullable()->index('ope_source_run_idx');
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_action', 16)->index('ope_source_action_idx');
            $table->string('company_external_identifier');
            $table->string('employee_identifier');
            $table->string('odessa_identifier')->nullable();
            $table->string('first_name');
            $table->string('paternal_last_name');
            $table->string('maternal_last_name')->nullable();
            $table->date('birth_date');
            $table->string('source_email')->nullable();
            $table->string('medical_attention_identifier')->nullable()->unique('ope_medical_identifier_unique');
            $table->string('membership_type')->default('institutional');
            $table->date('membership_start_date')->nullable();
            $table->date('membership_end_date')->nullable();
            $table->string('murguia_status', 24)->default('NOT_REQUESTED')->index('ope_murguia_status_idx');
            $table->timestamp('murguia_synced_at')->nullable();
            $table->string('link_status', 32)->default('PENDING_ACCOUNT')->index('ope_link_status_idx');
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('linked_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('linked_odessa_account_id')->nullable()->constrained('odessa_afiliate_accounts')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->string('status', 16)->default('PENDING')->index('ope_status_idx');
            $table->text('blocked_reason')->nullable();
            $table->json('data_quality_flags')->nullable();
            $table->json('source_snapshot_json');
            $table->json('metadata_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('active_company_employee_key')->nullable()->unique('ope_active_company_employee_unique');
            $table->string('active_odessa_identifier')->nullable()->unique('ope_active_odessa_unique');
            $table->timestamps();

            $table->index(['company_external_identifier', 'employee_identifier'], 'ope_company_employee_idx');
            $table->index('odessa_identifier', 'ope_odessa_identifier_idx');
            $table->index('source_email', 'ope_source_email_idx');
            $table->index(['status', 'link_status'], 'ope_status_link_idx');
        });

        Schema::create('odessa_pre_enrollment_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('odessa_pre_enrollment_id')->constrained('odessa_pre_enrollments')->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_type')->index();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odessa_pre_enrollment_audits');
        Schema::dropIfExists('odessa_pre_enrollments');
    }
};
