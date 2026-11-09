<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opaque_benefit_movements', fn (Blueprint $t) => $t->string('benefit_kind', 16)->default('credit'));
        Schema::table('medical_purchase_intents', function (Blueprint $t) {
            $t->string('benefit_kind', 16)->default('credit');
            $t->unsignedBigInteger('discount_cents')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('medical_purchase_intents', fn (Blueprint $t) => $t->dropColumn(['benefit_kind', 'discount_cents']));
        Schema::table('opaque_benefit_movements', fn (Blueprint $t) => $t->dropColumn('benefit_kind'));
    }
};
