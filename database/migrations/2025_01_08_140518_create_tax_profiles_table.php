<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return list<string>
     */
    private function extendedColumnNames(): array
    {
        return [
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
    }

    private function addExtendedColumnsIfMissing(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_profiles', 'razon_social')) {
                $table->string('razon_social')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'tipo_persona')) {
                $table->string('tipo_persona')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'fecha_emision_constancia')) {
                $table->string('fecha_emision_constancia')->nullable();
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
                $table->string('regimen_fiscal_original')->nullable();
            }
            if (! Schema::hasColumn('tax_profiles', 'codigo_postal_original')) {
                $table->string('codigo_postal_original')->nullable();
            }
        });
    }

    public function up(): void
    {
        if (! Schema::hasTable('tax_profiles')) {
            Schema::create('tax_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('rfc')->nullable();
                $table->string('zipcode')->nullable();
                $table->string('tax_regime')->nullable();
                $table->string('cfdi_use')->nullable();
                $table->string('fiscal_certificate')->nullable();
                $table->string('razon_social')->nullable();
                $table->string('tipo_persona')->nullable();
                $table->string('fecha_emision_constancia')->nullable();
                $table->date('fecha_inscripcion')->nullable();
                $table->string('estatus_sat')->nullable();
                $table->text('domicilio_fiscal')->nullable();
                $table->string('actividades_economicas')->nullable();
                $table->integer('tipo_persona_confianza')->default(0);
                $table->string('tipo_persona_detectado_por')->nullable();
                $table->string('hash_constancia')->nullable();
                $table->boolean('verificado_automaticamente')->default(false);
                $table->timestamp('fecha_verificacion')->nullable();
                $table->string('regimen_fiscal_original')->nullable();
                $table->string('codigo_postal_original')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });

            return;
        }

        $this->addExtendedColumnsIfMissing();
    }

    public function down(): void
    {
        if (! Schema::hasTable('tax_profiles')) {
            return;
        }

        $columnsToDrop = array_values(array_filter(
            $this->extendedColumnNames(),
            fn (string $column) => Schema::hasColumn('tax_profiles', $column),
        ));

        if ($columnsToDrop === []) {
            return;
        }

        Schema::table('tax_profiles', function (Blueprint $table) use ($columnsToDrop) {
            $table->dropColumn($columnsToDrop);
        });
    }
};
