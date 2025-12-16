<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_notifications', function (Blueprint $table) {
            // Agregar foreign keys para users y contacts
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('cascade')
                  ->comment('Usuario relacionado con la notificación');

            $table->foreignId('contact_id')
                  ->nullable()
                  ->constrained('contacts')
                  ->onDelete('cascade')
                  ->comment('Contacto relacionado con la notificación');

            // Agregar índices para mejor performance
            $table->index('user_id');
            $table->index('contact_id');
            $table->index(['user_id', 'contact_id']);
            $table->index(['notification_type', 'status']);
            
            // Si quieres agregar algún campo adicional para contexto
            $table->string('context')->nullable()->comment('Contexto adicional de la notificación');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_notifications', function (Blueprint $table) {
            // Eliminar las foreign keys primero
            $table->dropForeign(['user_id']);
            $table->dropForeign(['contact_id']);
            
            // Eliminar índices
            $table->dropIndex(['user_id']);
            $table->dropIndex(['contact_id']);
            $table->dropIndex(['user_id', 'contact_id']);
            $table->dropIndex(['notification_type', 'status']);
            
            // Eliminar columnas
            $table->dropColumn(['user_id', 'contact_id', 'context']);
        });
    }
};
