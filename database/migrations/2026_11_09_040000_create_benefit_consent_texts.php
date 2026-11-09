<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_consent_texts', function (Blueprint $t) {
            $t->id();
            $t->string('version', 128)->unique();
            $t->text('text');
            $t->char('digest', 64);
            $t->boolean('enabled')->default(false);
            $t->foreignId('operator_id')->constrained('users');
            $t->string('approval_ref', 128);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_consent_texts');
    }
};
