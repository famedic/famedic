<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {

            $table->id();

            $table->foreignId('customer_id');
            $table->foreignId('token_id')->nullable();

            $table->integer('amount_cents');

            $table->string('gateway')->default('efevoopay');

            $table->string('reference')->nullable();

            $table->string('status')->default('pending');

            $table->string('processor_code')->nullable();
            $table->string('processor_message')->nullable();

            $table->string('processor_transaction_id')->nullable();

            $table->json('raw_response')->nullable();

            $table->integer('retry_count')->default(0);

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
