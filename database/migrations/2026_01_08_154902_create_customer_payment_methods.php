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
        Schema::create('customer_payment_methods', function (Blueprint $table) {
            $table->id();
            
            // Relación con el cliente (NO con usuario directo)
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            
            // Gateway específico
            $table->string('gateway')->default('efevoopay')->index();
            $table->string('gateway_payment_method_id')->nullable()->index();
            $table->string('gateway_token')->nullable()->index(); // Token único de EfevooPay
            
            // Información de tarjeta (no sensible - PCI compliant)
            $table->string('last_four', 4);
            $table->string('brand')->nullable(); // visa, mastercard, amex
            $table->string('card_type')->nullable(); // credit, debit, prepaid
            $table->string('exp_month', 2);
            $table->string('exp_year', 4);
            
            // Alias para el usuario
            $table->string('alias')->nullable(); // "Mi Tarjeta Principal"
            
            // Metadatos y auditoría
            $table->json('metadata')->nullable();
            $table->json('gateway_response')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para optimización
            $table->index(['customer_id', 'is_default']);
            $table->index(['customer_id', 'is_active']);
            $table->index(['gateway', 'gateway_token']);
            $table->unique(['customer_id', 'gateway_token'], 'unique_customer_gateway_token');
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('customer_payment_methods');
    }
};
