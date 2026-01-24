<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\ActiveCampaignService;

class TestActiveCampaignWithDiscovery extends Command
{
    protected $signature = 'ac:discover-fields {user_id} {--validate} {--debug}';
    protected $description = 'Test ActiveCampaign field discovery and validation';

    public function handle(ActiveCampaignService $service)
    {
        $userId = $this->argument('user_id');
        $user = User::find($userId);

        if (!$user) {
            $this->error("User not found: {$userId}");
            return 1;
        }

        $this->info("🔍 ActiveCampaign Field Discovery Test");
        $this->info("========================================");
        $this->line("User: {$user->email} (ID: {$user->id})");

        // 1. Mostrar configuración actual
        $this->info("\n📋 Configuración actual:");
        $fieldMapping = config('activecampaign.field_mapping', []);
        
        $tableData = [];
        foreach ($fieldMapping as $field => $fieldId) {
            $tableData[] = [
                'Campo DB' => $field,
                'ID AC' => $fieldId ?? 'No configurado',
                'Valor' => $this->getFieldValueFromUser($user, $field),
            ];
        }
        
        $this->table(['Campo DB', 'ID AC', 'Valor'], $tableData);

        // 2. Obtener todos los campos de ActiveCampaign
        $this->info("\n📊 Campos disponibles en ActiveCampaign:");
        $allFields = $service->getAllCustomFields();
        
        $fieldsTable = [];
        foreach ($allFields as $field) {
            $fieldsTable[] = [
                'ID' => $field['id'],
                'Título' => $field['title'],
                'Tipo' => $field['type'],
                'Tag' => $field['perstag'],
            ];
        }
        
        $this->table(['ID', 'Título', 'Tipo', 'Tag'], $fieldsTable);

        // 3. Probar la conexión
        $this->info("\n🔌 Probando conexión a ActiveCampaign...");
        $connectionTest = $service->testConnection();
        
        if ($connectionTest) {
            $this->info("✅ Conexión exitosa");
        } else {
            $this->error("❌ Error de conexión");
            return 1;
        }

        // 4. Preparar datos del usuario
        $this->info("\n📝 Preparando datos del usuario...");
        $userData = $service->prepareUserData($user);
        $customFields = $userData['custom_fields'] ?? [];

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Email', $userData['email']],
                ['Nombre', $userData['first_name']],
                ['Apellido', $userData['last_name']],
                ['Teléfono', $userData['phone'] ?? 'N/A'],
                ['Campos personalizados', count($customFields)],
            ]
        );

        if (!empty($customFields)) {
            $this->info("Campos personalizados a enviar:");
            foreach ($customFields as $fieldId => $value) {
                $this->line("  • ID {$fieldId}: '{$value}'");
            }
        }

        if ($this->option('validate')) {
            $this->info("\n🚀 Iniciando sincronización de prueba...");
            
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
                $this->info("✅ Sincronización exitosa! Contact ID: {$result['contact_id']}");
                
                if (!empty($customFields) && isset($result['contact_id'])) {
                    $this->info("\n🔍 Verificando campos personalizados...");
                    sleep(3); // Esperar a que la API procese
                    
                    foreach ($customFields as $fieldId => $expectedValue) {
                        $this->info("Verificando campo {$fieldId}...");
                        
                        // Intentar varias veces
                        $actualValue = null;
                        for ($i = 0; $i < 3; $i++) {
                            $actual = $service->getFieldValue($result['contact_id'], $fieldId);
                            if ($actual && isset($actual['value'])) {
                                $actualValue = $actual['value'];
                                break;
                            }
                            sleep(1);
                        }
                        
                        // CORRECCIÓN: Extraer el valor fuera del string
                        $actualValueDisplay = $actualValue ?? "null";
                        $status = ($actualValue === $expectedValue) ? '✅' : '❌';
                        $this->line("{$status} Campo ID {$fieldId}: Esperado '{$expectedValue}', Obtenido '{$actualValueDisplay}'");
                    }
                }
            } else {
                $this->error("❌ Error en sincronización: {$result['error']}");
            }
        }

        // 5. Sugerencia de configuración
        $this->info("\n💡 Sugerencia de configuración para .env:");
        $this->line("# Campos personalizados de ActiveCampaign");
        
        foreach ($allFields as $field) {
            $tag = strtoupper($field['perstag'] ?? '');
            if (!empty($tag)) {
                $this->line("ACTIVE_CAMPAIGN_FIELD_{$tag}={$field['id']}  # {$field['title']}");
            }
        }

        return 0;
    }

    private function getFieldValueFromUser(User $user, string $field)
    {
        $value = $user->{$field} ?? null;
        
        if ($value instanceof \Carbon\Carbon) {
            return $value->format('Y-m-d');
        }
        
        if ($value instanceof \App\Enums\Gender) {
            return $value->value . ' (' . $value->label() . ')';
        }
        
        return $value ?? 'null';
    }
}