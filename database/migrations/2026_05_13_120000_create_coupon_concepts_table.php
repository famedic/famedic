<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coupon_concepts')) {
            Schema::create('coupon_concepts', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'coupon_concept_id')) {
                $table->foreignId('coupon_concept_id')
                    ->nullable()
                    ->after('description')
                    ->constrained('coupon_concepts')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('coupons', 'concept_other')) {
                $table->string('concept_other')->nullable()->after('coupon_concept_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (Schema::hasColumn('coupons', 'coupon_concept_id')) {
                $table->dropForeign(['coupon_concept_id']);
                $table->dropColumn('coupon_concept_id');
            }
            if (Schema::hasColumn('coupons', 'concept_other')) {
                $table->dropColumn('concept_other');
            }
        });

        Schema::dropIfExists('coupon_concepts');
    }
};
