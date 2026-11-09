<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('first_party_medical_checkouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('context_id')->constrained('opaque_benefit_contexts');
            $t->foreignId('user_id')->constrained('users');
            $t->char('application_hash', 64);
            $t->char('session_hash', 64);
            $t->char('reference_hash', 64)->unique();
            $t->text('payload_ciphertext');
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->string('purchase_ref', 48)->nullable()->unique();
            $t->timestamps();
            $t->unique(['context_id', 'application_hash'], 'first_party_medical_application_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_party_medical_checkouts');
    }
};
