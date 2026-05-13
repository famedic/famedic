<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->index();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('remaining_cents');
            $table->string('type')->default('balance'); // balance
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('used_at')->nullable()->index();
            $table->unique(['coupon_id', 'user_id']);
        });

        Schema::create('coupon_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('purchase_type', 32); // lab | pharmacy
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedInteger('amount_used_cents');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['purchase_type', 'purchase_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('type', 64)->index(); // coupon_assigned
            $table->string('title');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('laboratory_purchases', function (Blueprint $table) {
            $table->unsignedInteger('coupon_discount_cents')->nullable()->after('total_cents');
        });

        Schema::table('online_pharmacy_purchases', function (Blueprint $table) {
            $table->unsignedInteger('coupon_discount_cents')->nullable()->after('total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('online_pharmacy_purchases', function (Blueprint $table) {
            $table->dropColumn('coupon_discount_cents');
        });

        Schema::table('laboratory_purchases', function (Blueprint $table) {
            $table->dropColumn('coupon_discount_cents');
        });

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('coupon_transactions');
        Schema::dropIfExists('coupon_user');
        Schema::dropIfExists('coupons');
    }
};
