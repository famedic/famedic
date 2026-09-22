<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_gda_failure_logs', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 40);
            $table->unsignedBigInteger('laboratory_purchase_id')->nullable();
            $table->unsignedBigInteger('source_laboratory_purchase_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('administrator_user_id')->nullable();
            $table->string('brand', 32)->nullable();
            $table->string('failure_reason', 120);
            $table->string('gda_code_http', 32)->nullable();
            $table->string('gda_mensaje', 255)->nullable();
            $table->text('gda_description')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('requisition_value', 64)->nullable();
            $table->text('message');
            $table->json('response_summary')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'operation']);
            $table->index('laboratory_purchase_id');
            $table->index('source_laboratory_purchase_id');

            $table->foreign('laboratory_purchase_id', 'lab_gda_fail_logs_purchase_fk')
                ->references('id')
                ->on('laboratory_purchases')
                ->nullOnDelete();
            $table->foreign('source_laboratory_purchase_id', 'lab_gda_fail_logs_source_fk')
                ->references('id')
                ->on('laboratory_purchases')
                ->nullOnDelete();
            $table->foreign('customer_id', 'lab_gda_fail_logs_customer_fk')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
            $table->foreign('administrator_user_id', 'lab_gda_fail_logs_admin_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_gda_failure_logs');
    }
};
