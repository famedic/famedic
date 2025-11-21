<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            // Agregar relación con laboratory_quotes si no existe
            if (!Schema::hasColumn('laboratory_purchases', 'laboratory_quote_id')) {
                $table->foreignId('laboratory_quote_id')
                      ->nullable()
                      ->constrained()
                      ->nullOnDelete()
                      ->after('customer_id');
            }

            // 🆕 CAMPOS GDA ESPECÍFICOS
            if (!Schema::hasColumn('laboratory_purchases', 'gda_response')) {
                $table->json('gda_response')->nullable()->after('gda_order_id');
            }
            
            if (!Schema::hasColumn('laboratory_purchases', 'gda_acuse')) {
                $table->string('gda_acuse')->nullable()->after('gda_response');
            }
            
            if (!Schema::hasColumn('laboratory_purchases', 'gda_code_http')) {
                $table->string('gda_code_http')->nullable()->after('gda_acuse');
            }
            
            if (!Schema::hasColumn('laboratory_purchases', 'gda_mensaje')) {
                $table->string('gda_mensaje')->nullable()->after('gda_code_http');
            }
            
            // NOTA: El campo se llama 'gda_description' (no 'gda_descripcion')
            if (!Schema::hasColumn('laboratory_purchases', 'gda_description')) {
                $table->text('gda_description')->nullable()->after('gda_mensaje');
            }
            
            if (!Schema::hasColumn('laboratory_purchases', 'has_gda_warning')) {
                $table->boolean('has_gda_warning')->default(false)->after('gda_description');
            }
            
            if (!Schema::hasColumn('laboratory_purchases', 'gda_warning_message')) {
                $table->text('gda_warning_message')->nullable()->after('has_gda_warning');
            }
            
            if (!Schema::hasColumn('laboratory_purchases', 'pdf_base64')) {
                $table->longText('pdf_base64')->nullable()->after('gda_warning_message');
            }

            // Agregar campos de estado si no existen
            if (!Schema::hasColumn('laboratory_purchases', 'status')) {
                $table->string('status')->default('pending')->after('total_cents');
            }
            
            if (!Schema::hasColumn('laboratory_purchases', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('status');
            }

            // Agregar campos de timestamps para tracking
            if (!Schema::hasColumn('laboratory_purchases', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('expires_at');
            }
            
            if (!Schema::hasColumn('laboratory_purchases', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('paid_at');
            }
            
            if (!Schema::hasColumn('laboratory_purchases', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            }

            // 🆕 Agregar índices para mejor performance
            $table->index(['laboratory_quote_id'], 'lab_purchase_quote_idx');
            $table->index(['gda_order_id', 'gda_acuse'], 'lab_purchase_gda_idx');
            $table->index(['status', 'expires_at'], 'lab_purchase_status_idx');
            $table->index(['has_gda_warning'], 'lab_purchase_warning_idx');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            // Eliminar índices primero
            $table->dropIndex('lab_purchase_quote_idx');
            $table->dropIndex('lab_purchase_gda_idx');
            $table->dropIndex('lab_purchase_status_idx');
            $table->dropIndex('lab_purchase_warning_idx');

            // Eliminar foreign keys
            if (Schema::hasColumn('laboratory_purchases', 'laboratory_quote_id')) {
                $table->dropForeign(['laboratory_quote_id']);
            }

            // Eliminar columnas en orden inverso
            $columnsToDrop = [
                'cancelled_at',
                'completed_at',
                'paid_at',
                'expires_at',
                'status',
                'pdf_base64',
                'gda_warning_message',
                'has_gda_warning',
                'gda_description',
                'gda_mensaje',
                'gda_code_http',
                'gda_acuse',
                'gda_response',
                'laboratory_quote_id'
            ];

            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('laboratory_purchases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
