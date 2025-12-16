<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            // Campos para datos extraídos automáticamente
            $table->string('razon_social')->nullable()->after('name');
            $table->string('tipo_persona')->nullable()->after('razon_social'); // 'fisica' o 'moral'
            $table->string('fecha_emision_constancia')->nullable()->after('fiscal_certificate');
            $table->date('fecha_inscripcion')->nullable();
            $table->string('estatus_sat')->nullable();
            $table->text('domicilio_fiscal')->nullable();
            $table->string('actividades_economicas')->nullable();
            $table->integer('tipo_persona_confianza')->default(0); // 0-100%
            $table->string('tipo_persona_detectado_por')->nullable();
            $table->string('hash_constancia')->nullable(); // Para evitar duplicados
            $table->boolean('verificado_automaticamente')->default(false);
            $table->timestamp('fecha_verificacion')->nullable();
            
            // Campos existentes que se llenarán automáticamente
            $table->string('regimen_fiscal_original')->nullable()->after('tax_regime');
            $table->string('codigo_postal_original')->nullable()->after('zipcode');
        });
    }

    public function down(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'razon_social',
                'tipo_persona',
                'fecha_emision_constancia',
                'fecha_inscripcion',
                'estatus_sat',
                'domicilio_fiscal',
                'actividades_economicas',
                'tipo_persona_confianza',
                'tipo_persona_detectado_por',
                'hash_constancia',
                'verificado_automaticamente',
                'fecha_verificacion',
                'regimen_fiscal_original',
                'codigo_postal_original',
            ]);
        });
    }
};
