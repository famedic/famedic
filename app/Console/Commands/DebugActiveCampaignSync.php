<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\ActiveCampaignService;

class DebugActiveCampaignSync extends Command
{
    protected $signature = 'debug:activecampaign {user_id}';
    protected $description = 'Debug ActiveCampaign synchronization for a user';

    public function handle(ActiveCampaignService $service)
    {
        $userId = $this->argument('user_id');
        $user = User::find($userId);

        if (!$user) {
            $this->error("User not found: {$userId}");
            return 1;
        }

        $this->info("Debugging ActiveCampaign sync for user: {$user->email} (ID: {$user->id})");
        
        // Preparar datos
        $userData = $service->prepareUserData($user);
        $customFields = $userData['custom_fields'] ?? [];
        
        $this->table(
            ['Field ID', 'Value'],
            array_map(function($id, $value) {
                return [$id, $value];
            }, array_keys($customFields), $customFields)
        );
        
        // Mostrar datos del usuario
        $this->info("User data:");
        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $userData['email']],
                ['First Name', $userData['first_name']],
                ['Last Name', $userData['last_name']],
                ['Phone', $userData['phone'] ?? 'null'],
                ['Gender', $user->gender?->value ?? 'null'],
                ['Birth Date', $user->birth_date?->format('Y-m-d') ?? 'null'],
                ['State', $user->state ?? 'null'],
                ['Referred By', $user->referred_by ?? 'null'],
            ]
        );
        
        // Probar sincronización
        $contactData = [
            'email' => $userData['email'],
            'first_name' => $userData['first_name'],
            'last_name' => $userData['last_name'],
            'phone' => $userData['phone'] ?? null,
        ];
        
        $listId = config('activecampaign.lists.default', 5);
        $tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo')];
        
        $this->info("Synchronizing with custom fields...");
        
        $result = $service->syncContactWithCustomFields($contactData, $listId, $tags, $customFields);
        
        if ($result['success']) {
            $this->info("Success! Contact ID: {$result['contact_id']}");
            
            // Verificar campos
            if (!empty($customFields) && isset($result['contact_id'])) {
                $this->info("Verifying custom fields...");
                foreach ($customFields as $fieldId => $expectedValue) {
                    $actual = $service->getFieldValue($result['contact_id'], $fieldId);
                    
                    // CORRECCIÓN: Extraer el valor fuera del string
                    $actualValue = $actual['value'] ?? 'null';
                    $status = ($actual && $actual['value'] == $expectedValue) ? '✓' : '✗';
                    
                    $this->line("{$status} Field {$fieldId}: Expected '{$expectedValue}', Got '{$actualValue}'");
                }
            }
        } else {
            $this->error("Failed: {$result['error']}");
        }
        
        return 0;
    }
}