<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_result_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_result_status_id')
                ->constrained('laboratory_result_statuses')
                ->cascadeOnDelete();
            $table->foreignId('laboratory_notification_id')
                ->nullable()
                ->constrained('laboratory_notifications')
                ->nullOnDelete();
            $table->string('storage_path');
            $table->string('sha256', 64);
            $table->string('source', 40);
            $table->string('classification', 40)->default('unknown');
            $table->string('classification_reason', 80);
            $table->string('matched_rule', 80)->nullable();
            $table->string('classifier', 80);
            $table->timestamp('classified_at')->nullable();
            $table->timestamp('pdf_available_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['laboratory_result_status_id', 'sha256'],
                'lab_result_version_status_sha_unique'
            );
            $table->index('sha256', 'lab_result_version_sha_idx');
            $table->index(['classification', 'classified_at'], 'lab_result_version_classified_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_versions');
    }
};
