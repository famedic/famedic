<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\ActiveCampaignService;

class ACDebugSimple extends Command
{
    protected $signature = 'ac:debug {user_id}';
    protected $description = 'Simple debug para ActiveCampaign';

    public function handle(ActiveCampaignService $service)
    {
        $userId = $this->argument('user_id');
        $user = User::find($userId);

        if (!$user) {
            $this->error("Usuario no encontrado: {$userId}");
            return 1;
        }

        $this->info("=== DEBUG ACTIVECAMPAIGN ===");
        $this->line("Usuario: {$user->email}");
        $this->line("ID: {$user->id}");

        // 1. Verificar conexión
        $this->info("\n1. Probando conexión...");
        if ($service->testConnection()) {
            $this->info("✅ Conexión OK");
        } else {
            $this->error("❌ Conexión fallida");
            return 1;
        }

        // 2. Preparar datos
        $this->info("\n2. Preparando datos...");
        $userData = $service->prepareUserData($user);
        $customFields = $userData['custom_fields'] ?? [];

        $this->table(['Campo', 'Valor'], [
            ['Nombre', $userData['first_name']],
            ['Apellido', $userData['last_name']],
            ['Email', $userData['email']],
            ['Teléfono', $userData['phone'] ?? 'N/A'],
            ['Campos personalizados', count($customFields)],
        ]);

        if (!empty($customFields)) {
            $this->info("Campos personalizados:");
            foreach ($customFields as $id => $val) {
                $this->line("  • ID {$id} = '{$val}'");
            }
        }

        // 3. Buscar contacto existente
        $this->info("\n3. Buscando contacto existente...");
        $existing = $service->getContactByEmail($user->email);
        
        if ($existing) {
            $this->info("✅ Contacto existente encontrado:");
            $this->line("   ID: {$existing['id']}");
            $this->line("   Email: {$existing['email']}");
            
            // Mostrar fieldValues actuales
            $response = $service->client->get("/api/3/contacts/{$existing['id']}/fieldValues");
            if ($response->successful()) {
                $data = $response->json();
                $fieldValues = $data['fieldValues'] ?? $data;
                
                if (is_array($fieldValues) && !empty($fieldValues)) {
                    $this->info("   FieldValues actuales:");
                    foreach ($fieldValues as $fv) {
                        if (is_array($fv) && isset($fv['field'], $fv['value'])) {
                            $this->line("     • Field {$fv['field']} = '{$fv['value']}'");
                        }
                    }
                }
            }
        } else {
            $this->info("📭 No existe contacto en AC");
        }

        // 4. Preguntar si hacer sync
        if ($this->confirm('¿Deseas ejecutar la sincronización ahora?', false)) {
            $this->info("\n4. Ejecutando sincronización...");
            
            $contactData = [
                'email' => $userData['email'],
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'phone' => $userData['phone'] ?? null,
            ];
            
            $listId = config('activecampaign.lists.default', 5);
            $tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo')];
            
            $result = $service->syncContactWithCustomFields($contactData, $listId, $tags, $customFields);
            
            if ($result['success']) {
                $this->info("✅ Sincronización exitosa!");
                $this->line("   Contact ID: {$result['contact_id']}");
                $this->line("   Acción: {$result['action']}");
                
                // Verificar campos
                if (!empty($customFields)) {
                    $this->info("\n5. Verificando campos...");
                    sleep(3);
                    
                    foreach ($customFields as $fieldId => $expectedValue) {
                        $actual = $service->getFieldValue($result['contact_id'], $fieldId);
                        $actualValue = $actual['value'] ?? 'null';
                        $status = ($actualValue === $expectedValue) ? '✅' : '❌';
                        $this->line("{$status} Campo {$fieldId}: '{$expectedValue}' vs '{$actualValue}'");
                    }
                }
            } else {
                $this->error("❌ Error: {$result['error']}");
            }
        }

        return 0;
    }
}