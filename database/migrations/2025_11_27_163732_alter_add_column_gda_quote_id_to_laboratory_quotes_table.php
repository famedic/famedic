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
            // Agregar columna para el ID del proveedor externo (GDA)
            $table->string('gda_quote_id')
                  ->nullable()
                  ->after('id') // Opcional: colocar después del ID principal
                  ->comment('ID de la cotización en el sistema del proveedor GDA');
            
            // Agregar índice para mejor performance en búsquedas
            $table->index('gda_quote_id');
            
            // Opcional: agregar índice único si cada gda_quote_id debe ser único
            // $table->unique('gda_quote_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_quotes', function (Blueprint $table) {
            // Eliminar el índice primero
            $table->dropIndex(['gda_quote_id']);
            
            // Eliminar la columna
            $table->dropColumn('gda_quote_id');
        });
    }
};