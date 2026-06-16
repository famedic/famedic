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

        Schema::table('tax_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_profiles', 'razon_social')) {
                $table->string('razon_social')->nullable()->after('name');
            }
            if (! Schema::hasColumn('tax_profiles', 'tipo_persona')) {
                $table->string('tipo_persona')->nullable()->after('razon_social');
            }
            if (! Schema::hasColumn('tax_profiles', 'fecha_emision_constancia')) {
                $table->string('fecha_emision_constancia')->nullable()->after('fiscal_certificate');
            }
            if (! Schema::hasColumn('tax_profiles', 'fecha_inscripcion')) {
                $table->date('fecha_inscripcion')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'estatus_sat')) {
                $table->string('estatus_sat')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'domicilio_fiscal')) {
                $table->text('domicilio_fiscal')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'actividades_economicas')) {
                $table->string('actividades_economicas')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'tipo_persona_confianza')) {
                $table->integer('tipo_persona_confianza')->default(0);
            }
            if (! Schema::hasColumn('tax_profiles', 'tipo_persona_detectado_por')) {
                $table->string('tipo_persona_detectado_por')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'hash_constancia')) {
                $table->string('hash_constancia')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'verificado_automaticamente')) {
                $table->boolean('verificado_automaticamente')->default(false);
            }
            if (! Schema::hasColumn('tax_profiles', 'fecha_verificacion')) {
                $table->timestamp('fecha_verificacion')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'regimen_fiscal_original')) {
                $table->string('regimen_fiscal_original')->nullable()->after('tax_regime');
            }
            if (! Schema::hasColumn('tax_profiles', 'codigo_postal_original')) {
                $table->string('codigo_postal_original')->nullable()->after('zipcode');
            }
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

        $toDrop = array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn('tax_profiles', $column)
        ));

        if ($toDrop === []) {
            return;
        }

        Schema::table('tax_profiles', function (Blueprint $table) use ($toDrop) {
            $table->dropColumn($toDrop);
        });
    }
};
