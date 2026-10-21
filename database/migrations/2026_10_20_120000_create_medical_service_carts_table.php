<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medical_service_carts')) {
            return;
        }

        Schema::create('medical_service_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->enum('service_line', ['medical_services'])->default('medical_services');
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('credit_applied_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->timestamps();

            $table->unique(
                ['customer_id', 'service_line', 'status'],
                'medical_service_carts_customer_line_status_unique'
            );
            $table->index(['customer_id', 'service_line'], 'medical_service_carts_customer_line_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_service_carts');
    }
};
