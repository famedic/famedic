<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('efevoo_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('efevoo_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_id')->nullable()->unique();
            $table->string('reference')->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('MXN');
            $table->enum('status', [
                'pending', 'approved', 'declined', 'error', 'refunded'
            ])->default('pending');
            $table->string('response_code', 10)->nullable();
            $table->text('response_message')->nullable();
            $table->enum('transaction_type', [
                'tokenization', 'payment', 'refund', '3ds'
            ]);
            $table->json('metadata')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->string('cav')->nullable()->index();
            $table->integer('msi')->default(0);
            $table->string('fiid_comercio')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            $table->index(['status', 'created_at']);
            $table->index(['reference', 'transaction_type']);
            $table->index(['cav', 'status']);
        });
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('efevoo_transactions');
    }        
};
