<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->text('provider_token')->nullable();
            $table->string('brand')->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('exp_month', 2)->nullable();
            $table->string('exp_year', 4)->nullable();
            $table->string('affiliation_id')->nullable();
            $table->string('media_id')->nullable();
            $table->string('status')->default('active');
            $table->string('alias')->nullable();
            $table->string('card_holder')->nullable();
            $table->unsignedBigInteger('created_from_transaction_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
