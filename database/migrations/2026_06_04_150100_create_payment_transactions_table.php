<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('related');
            $table->string('provider');
            $table->string('flow');
            $table->string('folio', 12)->nullable();
            $table->string('reference')->nullable();
            $table->string('previous_reference')->nullable();
            $table->string('auth_code')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('MXN');
            $table->string('mode')->nullable();
            $table->string('status');
            $table->string('bnrg_codigo_proc', 1)->nullable();
            $table->string('bnrg_codigo_proc_trans', 1)->nullable();
            $table->string('bnrg_codigo_rechazo')->nullable();
            $table->string('bnrg_codigo_emisor')->nullable();
            $table->text('bnrg_texto')->nullable();
            $table->string('bnrg_estado_trans', 1)->nullable();
            $table->string('bnrg_tipo_trans', 4)->nullable();
            $table->json('raw_request')->nullable();
            $table->json('raw_response_headers')->nullable();
            $table->timestamps();

            $table->index(['provider', 'folio']);
            $table->index(['provider', 'reference']);
            $table->index(['flow', 'status']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreign('created_from_transaction_id')
                ->references('id')
                ->on('payment_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropForeign(['created_from_transaction_id']);
        });

        Schema::dropIfExists('payment_transactions');
    }
};
