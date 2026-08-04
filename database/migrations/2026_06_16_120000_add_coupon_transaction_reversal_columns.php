<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_transactions', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('amount_used_cents');
            $table->foreignIdFor(User::class, 'reversed_by_user_id')
                ->nullable()
                ->after('reversed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('reversal_reason', 64)->nullable()->after('reversed_by_user_id');

            $table->index('reversed_at');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_transactions', function (Blueprint $table) {
            $table->dropForeign(['reversed_by_user_id']);
            $table->dropIndex(['reversed_at']);
            $table->dropColumn([
                'reversed_at',
                'reversed_by_user_id',
                'reversal_reason',
            ]);
        });
    }
};
