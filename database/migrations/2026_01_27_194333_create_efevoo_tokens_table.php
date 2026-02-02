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
        Schema::create('efevoo_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('alias')->nullable();
            $table->string('client_token', 500)->nullable();
            $table->text('card_token')->nullable();
            $table->string('card_last_four', 4);
            $table->string('card_brand')->nullable();
            $table->string('card_expiration', 4); // MMYY
            $table->string('card_holder')->nullable();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->enum('environment', ['test', 'production'])->default('test');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            //$table->index(['card_token', 'environment']);
            $table->index(['customer_id', 'is_active']);
            //$table->unique(['card_token', 'environment']);
        });
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('efevoo_tokens');
    }
};
