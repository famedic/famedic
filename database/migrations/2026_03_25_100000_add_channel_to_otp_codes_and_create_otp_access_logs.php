<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('channel', 16)->default('sms')->after('laboratory_purchase_id');
        });

        Schema::create('otp_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('laboratory_purchase_id')->nullable()->constrained('laboratory_purchases')->nullOnDelete();
            $table->string('event', 64);
            $table->string('channel', 16)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
            $table->index('laboratory_purchase_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_access_logs');

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
