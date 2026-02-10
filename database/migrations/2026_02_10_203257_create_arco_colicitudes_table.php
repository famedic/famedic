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
        Schema::create('arco_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('folio')->unique();
            $table->string('nombre_completo');
            $table->date('fecha_nacimiento')->nullable();
            $table->string('rfc')->nullable();
            
            // Dirección
            $table->string('calle')->nullable();
            $table->string('numero_exterior')->nullable();
            $table->string('numero_interior')->nullable();
            $table->string('colonia')->nullable();
            $table->string('municipio_estado')->nullable();
            $table->string('codigo_postal')->nullable();
            
            // Contacto
            $table->string('telefono_fijo')->nullable();
            $table->string('telefono_celular')->nullable();
            
            // Derechos solicitados
            $table->boolean('derecho_acceso')->default(false);
            $table->boolean('derecho_rectificacion')->default(false);
            $table->boolean('derecho_cancelacion')->default(false);
            $table->boolean('derecho_oposicion')->default(false);
            $table->boolean('derecho_revocacion')->default(false);
            
            // Información de la solicitud
            $table->text('razon_solicitud');
            $table->enum('solicitado_por', ['titular', 'representante'])->default('titular');
            
            // Documentación (opcional por ahora)
            $table->string('documento_identificacion_path')->nullable();
            $table->string('documento_representacion_path')->nullable();
            
            // Estado del trámite
            $table->enum('estado', ['pendiente', 'en_proceso', 'atendida', 'rechazada'])->default('pendiente');
            $table->text('respuesta')->nullable();
            $table->date('fecha_respuesta')->nullable();
            $table->string('numero_oficio')->nullable();
            
            // Metadatos
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            
            // Índices
            $table->index('folio');
            $table->index('estado');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arco_solicitudes');
    }
};
