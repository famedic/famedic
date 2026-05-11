<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_appointment_interactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_appointment_id');
            $table->string('type', 64);
            $table->text('body')->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('laboratory_appointment_id', 'lab_appt_ix_appt_fk')
                ->references('id')->on('laboratory_appointments')->cascadeOnDelete();
            $table->foreign('admin_user_id', 'lab_appt_ix_user_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['laboratory_appointment_id', 'type'], 'lab_appt_ix_appt_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_appointment_interactions');
    }
};
