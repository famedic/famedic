<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('efevoo_3ds_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('card_last_four', 4);
            $table->decimal('amount', 10, 2);
            $table->string('status', 50)->default('pending');
            $table->string('order_id')->nullable();
            $table->text('token_3dsecure')->nullable();
            $table->text('url_3dsecure')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->json('status_check_response')->nullable();
            $table->json('callback_data')->nullable();
            $table->foreignId('efevoo_token_id')->nullable()->constrained('efevoo_tokens');
            $table->text('error_message')->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamp('callback_received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('order_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('efevoo_3ds_sessions');
    }
};
