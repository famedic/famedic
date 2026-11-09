<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_recovery_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('operator_id')->constrained('users');
            $t->foreignId('intent_id')->constrained('medical_purchase_intents');
            $t->string('action', 24);
            $t->string('approval_ref', 128);
            $t->string('result_state', 32);
            $t->timestamps();
            $t->unique(['intent_id', 'action', 'approval_ref'], 'medical_recovery_once');
        });
        Schema::table('medical_settlement_outbox', function (Blueprint $t) {
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->foreignId('operator_id')->nullable()->constrained('users');
            $t->string('approval_ref', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_recovery_audit');
        Schema::table('medical_settlement_outbox', function (Blueprint $t) {
            $t->dropConstrainedForeignId('operator_id');
            $t->dropColumn(['claimed_at', 'delivered_at', 'approval_ref']);
        });
    }
};
