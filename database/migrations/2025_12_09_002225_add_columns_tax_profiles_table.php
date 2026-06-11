<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_profiles')) {
            return;
        }

        $this->addColumnIfMissing('razon_social', function (Blueprint $table) {
            $table->string('razon_social')->nullable()->after('name');
        });
        $this->addColumnIfMissing('tipo_persona', function (Blueprint $table) {
            $table->string('tipo_persona')->nullable()->after('razon_social');
        });
        $this->addColumnIfMissing('fecha_emision_constancia', function (Blueprint $table) {
            $table->string('fecha_emision_constancia')->nullable()->after('fiscal_certificate');
        });
        $this->addColumnIfMissing('fecha_inscripcion', function (Blueprint $table) {
            $table->date('fecha_inscripcion')->nullable();
        });
        $this->addColumnIfMissing('estatus_sat', function (Blueprint $table) {
            $table->string('estatus_sat')->nullable();
        });
        $this->addColumnIfMissing('domicilio_fiscal', function (Blueprint $table) {
            $table->text('domicilio_fiscal')->nullable();
        });
        $this->addColumnIfMissing('actividades_economicas', function (Blueprint $table) {
            $table->string('actividades_economicas')->nullable();
        });
        $this->addColumnIfMissing('tipo_persona_confianza', function (Blueprint $table) {
            $table->integer('tipo_persona_confianza')->default(0);
        });
        $this->addColumnIfMissing('tipo_persona_detectado_por', function (Blueprint $table) {
            $table->string('tipo_persona_detectado_por')->nullable();
        });
        $this->addColumnIfMissing('hash_constancia', function (Blueprint $table) {
            $table->string('hash_constancia')->nullable();
        });
        $this->addColumnIfMissing('verificado_automaticamente', function (Blueprint $table) {
            $table->boolean('verificado_automaticamente')->default(false);
        });
        $this->addColumnIfMissing('fecha_verificacion', function (Blueprint $table) {
            $table->timestamp('fecha_verificacion')->nullable();
        });
        $this->addColumnIfMissing('regimen_fiscal_original', function (Blueprint $table) {
            $table->string('regimen_fiscal_original')->nullable()->after('tax_regime');
        });
        $this->addColumnIfMissing('codigo_postal_original', function (Blueprint $table) {
            $table->string('codigo_postal_original')->nullable()->after('zipcode');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tax_profiles')) {
            return;
        }

        $columns = [
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
        ];

        $existingColumns = array_filter($columns, fn (string $column) => Schema::hasColumn('tax_profiles', $column));

        if ($existingColumns === []) {
            return;
        }

        Schema::table('tax_profiles', function (Blueprint $table) use ($existingColumns) {
            $table->dropColumn($existingColumns);
        });
    }

    private function addColumnIfMissing(string $column, callable $definition): void
    {
        if (Schema::hasColumn('tax_profiles', $column)) {
            return;
        }

        Schema::table('tax_profiles', $definition);
    }
};
