<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_result_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_result_status_id')
                ->constrained('laboratory_result_statuses')
                ->cascadeOnDelete();
            $table->foreignId('laboratory_result_version_id')
                ->nullable()
                ->constrained('laboratory_result_versions')
                ->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->string('actor_type', 80)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(
                ['laboratory_result_status_id', 'event_type'],
                'lab_result_event_status_type_idx'
            );
            $table->index('created_at', 'lab_result_event_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_events');
    }
};
