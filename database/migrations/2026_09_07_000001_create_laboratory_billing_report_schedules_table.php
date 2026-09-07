<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_billing_report_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(false)->index();
            $table->json('weekdays')->nullable();
            $table->time('send_time')->nullable();
            $table->string('timezone')->default('America/Monterrey');
            $table->string('period_type')->default('previous_day');
            $table->json('filters')->nullable();
            $table->json('included_sections')->nullable();
            $table->json('recipients')->nullable();
            $table->boolean('include_excel')->default(true);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_billing_report_schedules');
    }
};
