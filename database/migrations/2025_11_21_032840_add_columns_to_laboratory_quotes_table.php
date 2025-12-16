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
        Schema::table('laboratory_quotes', function (Blueprint $table) {
            // Agregar campos similares a laboratory_purchases
            if (!Schema::hasColumn('laboratory_quotes', 'gda_order_id')) {
                $table->string('gda_order_id')->nullable()->after('laboratory_brand');
            }
            
            if (!Schema::hasColumn('laboratory_quotes', 'patient_name')) {
                $table->string('patient_name')->nullable()->after('gda_order_id');
            }
            
            if (!Schema::hasColumn('laboratory_quotes', 'patient_paternal_lastname')) {
                $table->string('patient_paternal_lastname')->nullable()->after('patient_name');
            }
            
            if (!Schema::hasColumn('laboratory_quotes', 'patient_maternal_lastname')) {
                $table->string('patient_maternal_lastname')->nullable()->after('patient_paternal_lastname');
            }
            
            if (!Schema::hasColumn('laboratory_quotes', 'patient_phone')) {
                $table->string('patient_phone')->nullable()->after('patient_maternal_lastname');
            }
            
            if (!Schema::hasColumn('laboratory_quotes', 'patient_birth_date')) {
                $table->date('patient_birth_date')->nullable()->after('patient_phone');
            }
            
            if (!Schema::hasColumn('laboratory_quotes', 'patient_gender')) {
                $table->enum('patient_gender', ['1', '2'])->nullable()->after('patient_birth_date');
            }
            
            if (!Schema::hasColumn('laboratory_quotes', 'laboratory_purchase_id')) {
                $table->foreignId('laboratory_purchase_id')
                      ->nullable()
                      ->constrained()
                      ->nullOnDelete()
                      ->after('appointment_id');
            }
            
            // Renombrar purchase_id si existe o crear
            if (Schema::hasColumn('laboratory_quotes', 'purchase_id')) {
                $table->string('purchase_id')->nullable()->change();
            } else {
                $table->string('purchase_id')->nullable()->after('laboratory_purchase_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_quotes', function (Blueprint $table) {
            $table->dropColumn([
                'gda_order_id',
                'patient_name',
                'patient_paternal_lastname',
                'patient_maternal_lastname',
                'patient_phone',
                'patient_birth_date',
                'patient_gender',
                'laboratory_purchase_id'
            ]);
            
            $table->dropForeign(['laboratory_purchase_id']);
        });
    }
};
