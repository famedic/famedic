<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_appointments', function (Blueprint $table) {
            $table->timestamp('phone_call_intent_at')->nullable()->after('notes');
            $table->dateTime('callback_availability_starts_at')->nullable()->after('phone_call_intent_at');
            $table->dateTime('callback_availability_ends_at')->nullable()->after('callback_availability_starts_at');
            $table->text('patient_callback_comment')->nullable()->after('callback_availability_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_appointments', function (Blueprint $table) {
            $table->dropColumn([
                'phone_call_intent_at',
                'callback_availability_starts_at',
                'callback_availability_ends_at',
                'patient_callback_comment',
            ]);
        });
    }
};
