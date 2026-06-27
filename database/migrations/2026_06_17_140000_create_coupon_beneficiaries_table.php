<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coupon_beneficiaries')) {
            return;
        }

        Schema::create('coupon_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('child_coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('email_normalized')->index();
            $table->string('first_name')->nullable();
            $table->string('paternal_lastname')->nullable();
            $table->string('maternal_lastname')->nullable();
            $table->string('status')->index();
            $table->string('source')->index();
            $table->string('import_batch_id')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['parent_coupon_id', 'email_normalized']);
            $table->index('user_id');
            $table->index('child_coupon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_beneficiaries');
    }
};
