<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RC2 Batch 2 · A1 (contract rev7 L08/L13b): opaque, non-sequential ids that Leo receives for the customer's own
 * family members and tax profiles. The reference is random (OpaqueReference), never derived from the internal id;
 * only its sha256 is indexed and the plain reference is stored encrypted (same pattern as the opaque benefit store).
 * Additive table, no change to existing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_opaque_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->char('ref_hash', 64)->unique();
            $table->text('ref_ciphertext');
            $table->timestamps();
            $table->unique(['customer_id', 'subject_type', 'subject_id'], 'api_opaque_identifiers_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_opaque_identifiers');
    }
};
