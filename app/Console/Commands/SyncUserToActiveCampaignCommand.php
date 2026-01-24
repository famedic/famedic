<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ActiveCampaignService;
use Illuminate\Console\Command;

class SyncUserToActiveCampaignCommand extends Command
{
    protected $signature = 'ac:sync-user 
                            {--email= : Email del usuario}
                            {--id= : ID del usuario}
                            {--test : Crear usuario de prueba}
                            {--force : Forzar recreación de contacto}';
    
    protected $description = 'Sincronizar usuario específico con ActiveCampaign';

    public function handle(ActiveCampaignService $service)
    {
        $this->info('🔄 Sincronizando usuario con ActiveCampaign');
        
        // Opción de test
        if ($this->option('test')) {
            return $this->createTestUser($service);
        }
        
        // Obtener usuario
        $user = $this->getUser();
        
        if (!$user) {
            $this->error('Usuario no encontrado');
            return 1;
        }
        
        $this->showUserInfo($user);
        
        if (!$this->confirm('¿Continuar con la sincronización?')) {
            return 0;
        }
        
        return $this->syncUser($user, $service);
    }
    
    private function createTestUser(ActiveCampaignService $service)
    {
        $this->info('👤 Creando usuario de prueba...');
        
        $testEmail = 'test_' . time() . '@famedic.com';
        
        $user = User::create([
            'name' => 'Test',
            'paternal_lastname' => 'ActiveCampaign',
            'maternal_lastname' => 'Integration',
            'email' => $testEmail,
            'phone' => '+528111111111',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => 1,
            'state' => 'NL',
            'password' => bcrypt('password123'),
        ]);
        
        $this->info("✅ Usuario de prueba creado: {$testEmail}");
        
        return $this->syncUser($user, $service);
    }
    
    private function getUser(): ?User
    {
        if ($email = $this->option('email')) {
            return User::where('email', $email)->first();
        }
        
        if ($id = $this->option('id')) {
            return User::find($id);
        }
        
        // Mostrar últimos 5 usuarios
        $users = User::latest()->limit(5)->get(['id', 'email', 'name', 'created_at']);
        
        $this->table(
            ['ID', 'Email', 'Nombre', 'Creado'],
            $users->map(fn($u) => [$u->id, $u->email, $u->name, $u->created_at->format('Y-m-d H:i')])
        );
        
        $choice = $this->choice(
            'Selecciona una opción:',
            ['Ingresar email', 'Ingresar ID', 'Usar último', 'Cancelar']
        );
        
        switch ($choice) {
            case 'Ingresar email':
                $email = $this->ask('Email del usuario:');
                return User::where('email', $email)->first();
                
            case 'Ingresar ID':
                $id = $this->ask('ID del usuario:');
                return User::find($id);
                
            case 'Usar último':
                return User::latest()->first();
                
            default:
                return null;
        }
    }
    
    private function showUserInfo(User $user): void
    {
        $this->table(
            ['Campo', 'Valor'],
            [
                ['ID', $user->id],
                ['Email', $user->email],
                ['Nombre Completo', $user->full_name],
                ['Teléfono', $user->phone],
                ['Estado', $user->state ?? 'N/A'],
                ['Fecha Nacimiento', $user->birth_date?->format('Y-m-d') ?? 'N/A'],
            ]
        );
    }
    
    private function syncUser(User $user, ActiveCampaignService $service): int
    {
        $this->info('🔄 Preparando datos para ActiveCampaign...');
        
        $userData = [
            'email' => $user->email,
            'first_name' => $user->name,
            'last_name' => trim(($user->paternal_lastname ?? '') . ' ' . ($user->maternal_lastname ?? '')),
            'phone' => $user->phone,
        ];
        
        $this->table(
            ['Campo AC', 'Valor'],
            [
                ['email', $userData['email']],
                ['firstName', $userData['first_name']],
                ['lastName', $userData['last_name']],
                ['phone', $userData['phone']],
            ]
        );
        
        $listId = config('activecampaign.lists.default', 5);
        $tagName = config('activecampaign.tags.registro_nuevo', 'RegistroNuevo');
        
        $this->info("📋 Configuración:");
        $this->line("   • List ID: {$listId}");
        $this->line("   • Tag: {$tagName}");
        
        if ($this->option('force')) {
            $this->warn('⚠️ Modo FORCE: Se creará nuevo contacto incluso si existe');
        }
        
        $this->info("\n🚀 Enviando a ActiveCampaign...");
        
        try {
            // Buscar contacto existente
            $existingContact = $service->getContactByEmail($user->email);
            
            if ($existingContact && !$this->option('force')) {
                $this->info("✅ Contacto ya existe en ActiveCampaign");
                $this->line("   • Contact ID: {$existingContact['id']}");
                $this->line("   • Creado: " . ($existingContact['created'] ?? 'N/A'));
                
                // Verificar si está en la lista
                $this->info("🔍 Verificando lista y tag...");
                
                // Agregar a lista si no está
                $service->addContactToList($existingContact['id'], $listId);
                $this->line("   • Agregado a lista: ✅");
                
                // Agregar tag
                $service->addTagToContact($existingContact['id'], $tagName);
                $this->line("   • Tag agregado: ✅");
                
                $this->info("\n🎯 Contacto actualizado exitosamente!");
                return 0;
            }
            
            // Crear/actualizar contacto
            $result = $service->syncContact($userData, $listId, [$tagName]);
            
            if ($result['success']) {
                $this->info("\n✅ Sincronización exitosa!");
                $this->line("   • Contact ID: {$result['contact_id']}");
                $this->line("   • Acción: {$result['action']}");
                $this->line("   • Lista: {$listId}");
                $this->line("   • Tag: {$tagName}");
                
                // Mostrar URL para ver en ActiveCampaign
                $baseUrl = str_replace('/api/3', '', config('activecampaign.api.base_url'));
                $this->info("\n🔗 Ver en ActiveCampaign:");
                $this->line("   {$baseUrl}/app/contacts/{$result['contact_id']}");
                
                return 0;
            } else {
                $this->error("❌ Error: {$result['error']}");
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Excepción: " . $e->getMessage());
            $this->line("Archivo: " . $e->getFile() . ":" . $e->getLine());
            return 1;
        }
    }
}