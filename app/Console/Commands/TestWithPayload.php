<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LaboratoryNotification;
use App\Actions\Laboratories\GetGDAResultsAction;

class TestWithPayload extends Command
{
    protected $signature = 'gda:test-payload {notificationId : ID de la notificación}';
    protected $description = 'Probar GDA usando datos del payload';

    public function handle()
    {
        $notificationId = $this->argument('notificationId');
        
        $notification = LaboratoryNotification::find($notificationId);
        
        if (!$notification) {
            $this->error("❌ Notificación #{$notificationId} no encontrada");
            return 1;
        }
        
        $this->info("🔍 Notificación #{$notification->id}");
        $this->line("GDA Order ID: {$notification->gda_order_id}");
        $this->line("Tiene PDF: " . ($notification->results_pdf_base64 ? '✅ SÍ' : '❌ NO'));
        
        // Obtener payload
        $payload = $notification->payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        
        if (!$payload || !is_array($payload)) {
            $this->error("❌ No se pudo obtener el payload");
            return 1;
        }
        
        $this->info("\n📋 Datos del payload:");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['marca', $payload['header']['marca'] ?? 'NULL'],
                ['convenio', $payload['requisition']['convenio'] ?? 'NULL'],
                ['id', $payload['id'] ?? 'NULL'],
                ['acuse', $payload['GDA_menssage']['acuse'] ?? 'NULL'],
            ]
        );
        
        // Verificar en configuración
        $marca = $payload['header']['marca'] ?? null;
        $convenio = $payload['requisition']['convenio'] ?? null;
        
        $brands = config('services.gda.brands', []);
        $found = false;
        
        foreach ($brands as $key => $config) {
            $brandId = (int) ($config['brand_id'] ?? 0);
            $agreementId = (int) ($config['brand_agreement_id'] ?? 0);
            
            if ($brandId === (int)$marca || $agreementId === (int)$convenio) {
                $this->info("✅ Coincidencia encontrada:");
                $this->table(
                    ['Config', 'Valor'],
                    [
                        ['Clave', $key],
                        ['brand_id', $brandId],
                        ['brand_agreement_id', $agreementId],
                        ['token', $config['token'] ? 'PRESENTE' : 'AUSENTE'],
                        ['Coincide por', $brandId === (int)$marca ? 'marca' : 'convenio'],
                    ]
                );
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $this->warn("⚠️ No se encontró coincidencia exacta en configuración");
        }
        
        if (!$this->confirm('¿Probar solicitud a GDA?', false)) {
            return 0;
        }
        
        try {
            $this->info("🚀 Enviando solicitud...");
            
            $action = app(GetGDAResultsAction::class);
            $results = $action($notification->gda_order_id, $payload);
            
            $this->info("✅ Éxito!");
            $this->line("PDF recibido: " . (!empty($results['infogda_resultado_b64']) ? '✅ SÍ' : '❌ NO'));
            $this->line("Tamaño: " . strlen($results['infogda_resultado_b64'] ?? '') . ' bytes');
            $this->line("Nuevo acuse: " . ($results['GDA_menssage']['acuse'] ?? 'NO ACUSE'));
            
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
}