<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_contexts')) {
            return;
        }

        Schema::table('account_contexts', function (Blueprint $table) {
            $table->foreignId('otp_challenge_id')->nullable()->change();
            $table->timestamp('verified_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('account_contexts')) {
            return;
        }

        Schema::table('account_contexts', function (Blueprint $table) {
            $table->foreignId('otp_challenge_id')->nullable(false)->change();
            $table->timestamp('verified_at')->nullable(false)->change();
        });
    }
};
