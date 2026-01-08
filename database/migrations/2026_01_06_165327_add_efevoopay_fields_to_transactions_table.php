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
        Schema::table('transactions', function (Blueprint $table) {
            // Agregar campos para múltiples gateways
            $table->string('gateway')->default('stripe')->index()->after('details');
            $table->string('gateway_transaction_id')->nullable()->index()->after('gateway');
            $table->string('gateway_status')->nullable()->after('gateway_transaction_id');
            $table->json('gateway_response')->nullable()->after('gateway_status');
            $table->string('gateway_token')->nullable()->index()->after('gateway_response');
            $table->timestamp('gateway_processed_at')->nullable()->after('gateway_token');
            $table->text('description')->nullable()->after('details');
            //$table->integer('customer_id')->unsigned()->nullable()->after('id');
            
            // Índices compuestos para búsquedas eficientes
            $table->index(['gateway', 'gateway_status']);
            $table->index(['gateway', 'created_at']);//'customer_id', 
        });
    }
    
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'gateway',
                'gateway_transaction_id',
                'gateway_status',
                'gateway_response',
                'gateway_token',
                'gateway_processed_at',
                'description',
            ]);
            
            $table->dropIndex(['gateway', 'gateway_status']);
            $table->dropIndex(['gateway', 'created_at']);//'customer_id', 
        });
    }
};
