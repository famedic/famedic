<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odessa_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status', 24)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_filename');
            $table->string('murguia_filename')->nullable();
            $table->string('source_path');
            $table->string('murguia_path')->nullable();
            $table->string('export_path')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('unique_collaborators')->default(0);
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->unsignedInteger('manual_review_count')->default(0);
            $table->unsignedInteger('not_found_count')->default(0);
            $table->unsignedInteger('complete_count')->default(0);
            $table->unsignedInteger('email_different_count')->default(0);
            $table->unsignedInteger('without_membership_count')->default(0);
            $table->unsignedInteger('expired_membership_count')->default(0);
            $table->unsignedInteger('famedic_and_murguia_count')->default(0);
            $table->unsignedInteger('famedic_not_murguia_count')->default(0);
            $table->unsignedInteger('murguia_not_famedic_count')->default(0);
            $table->unsignedInteger('pending_review_count')->default(0);
            $table->json('summary_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odessa_reconciliation_runs');
    }
};
