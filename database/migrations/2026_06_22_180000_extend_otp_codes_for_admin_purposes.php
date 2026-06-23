<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            if (Schema::hasColumn('otp_codes', 'laboratory_purchase_id')) {
                $table->dropForeign(['laboratory_purchase_id']);
            }
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('laboratory_purchase_id')->nullable()->change();
            $table->string('purpose', 32)->default('lab_results')->after('user_id');
            $table->uuid('challenge_id')->nullable()->after('purpose');
            $table->index(['user_id', 'purpose', 'challenge_id', 'status'], 'otp_codes_user_purpose_challenge_status');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->foreign('laboratory_purchase_id')
                ->references('id')
                ->on('laboratory_purchases')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex('otp_codes_user_purpose_challenge_status');
            $table->dropColumn(['purpose', 'challenge_id']);
        });
    }
};
