<?php

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Coupon::class)->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('promo_type', 32)->default('shared');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedSmallInteger('max_uses_per_user')->default(1);
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedInteger('reserved_count')->default(0);
            $table->foreignIdFor(User::class, 'assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_email')->nullable();
            $table->string('assigned_phone', 32)->nullable();
            $table->string('influencer_name')->nullable();
            $table->string('event_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignIdFor(User::class, 'created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('code');
            $table->index(['is_active', 'promo_type']);
        });

        Schema::create('promo_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Coupon::class)->nullable()->constrained()->nullOnDelete();
            $table->string('purchase_type', 32)->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->string('status', 32)->default('validated');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->string('validation_token', 64);
            $table->string('cart_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique('validation_token');
            $table->index(['promo_code_id', 'user_id', 'status']);
            $table->index(['purchase_type', 'purchase_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promo_codes');
    }
};
