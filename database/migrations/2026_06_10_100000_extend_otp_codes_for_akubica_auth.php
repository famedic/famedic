<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['laboratory_purchase_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE otp_codes MODIFY user_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE otp_codes MODIFY laboratory_purchase_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->unsignedBigInteger('laboratory_purchase_id')->nullable()->change();
            });
        }

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('email')->nullable()->after('laboratory_purchase_id');
            $table->string('purpose', 32)->nullable()->after('email');
            $table->json('payload')->nullable()->after('purpose');
            $table->unsignedTinyInteger('max_attempts')->default(5)->after('attempts');
            $table->timestamp('used_at')->nullable()->after('verified_at');
            $table->string('ip_address', 45)->nullable()->after('used_at');
            $table->text('user_agent')->nullable()->after('ip_address');

            $table->index(['email', 'purpose', 'status'], 'otp_codes_email_purpose_status_index');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('laboratory_purchase_id')->references('id')->on('laboratory_purchases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['laboratory_purchase_id']);
            $table->dropIndex('otp_codes_email_purpose_status_index');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn([
                'email',
                'purpose',
                'payload',
                'max_attempts',
                'used_at',
                'ip_address',
                'user_agent',
            ]);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE otp_codes MODIFY user_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE otp_codes MODIFY laboratory_purchase_id BIGINT UNSIGNED NOT NULL');
        } else {
            Schema::table('otp_codes', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
                $table->unsignedBigInteger('laboratory_purchase_id')->nullable(false)->change();
            });
        }

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('laboratory_purchase_id')->references('id')->on('laboratory_purchases')->cascadeOnDelete();
        });
    }
};
