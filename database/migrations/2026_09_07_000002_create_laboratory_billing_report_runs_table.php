<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_billing_report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->nullable()->constrained('laboratory_billing_report_schedules')->nullOnDelete();
            $table->string('run_type')->index();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('pending')->index();
            $table->timestamp('intended_for_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('backlog_as_of')->nullable();
            $table->json('recipients')->nullable();
            $table->json('filters')->nullable();
            $table->json('metrics')->nullable();
            $table->string('delivery_method')->nullable();
            $table->string('file_disk')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('link_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'run_type', 'status']);
            $table->index(['schedule_id', 'intended_for_at']);
            $table->index(['file_disk', 'file_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_billing_report_runs');
    }
};
