<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('laboratory_notifications', function (Blueprint $table) {
            $table->id();
            
            // Relaciones con cotizaciones y pedidos
            $table->foreignId('laboratory_quote_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('laboratory_purchase_id')->nullable()->constrained()->onDelete('cascade');
            
            // Datos de identificación GDA
            $table->string('gda_order_id')->nullable(); // FB0L122455
            $table->string('gda_external_id')->nullable(); // MDPROD-412924
            $table->string('gda_acuse')->nullable(); // UUID único
            $table->string('notification_type'); // notification, results, status_update
            
            // Estado y tipo
            $table->string('status'); // received, processed, error
            $table->string('gda_status')->nullable(); // completed, in-progress, cancelled
            $table->string('resource_type')->nullable(); // ServiceRequest, ServiceRequestCotizacion
            
            // Payload completo
            $table->json('payload');
            $table->json('gda_message')->nullable();
            
            // Resultados (para tipo results)
            $table->longText('results_pdf_base64')->nullable();
            $table->timestamp('results_received_at')->nullable();
            
            // Timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Índices CON NOMBRES MÁS CORTOS
            $table->index('gda_acuse', 'lab_notif_acuse_index');
            $table->index('gda_order_id', 'lab_notif_order_id_index');
            $table->index('notification_type', 'lab_notif_type_index');
            $table->index(['laboratory_quote_id', 'laboratory_purchase_id'], 'lab_notif_entities_index');
            $table->index('created_at', 'lab_notif_created_at_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('laboratory_notifications');
    }
};