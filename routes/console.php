<?php

use App\Models\User;
use App\Services\ActiveCampaignService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('activecampaign:test', function () {
    $this->info('=== ActiveCampaign Integration Test ===');
    
    // Verificar configuración
    $this->info("\n1. Configuration Check:");
    $this->line("   API Base URL: " . config('activecampaign.api.base_url', 'NOT SET'));
    $this->line("   Sync Enabled: " . (config('activecampaign.sync.enabled') ? '✅ Yes' : '❌ No'));
    $this->line("   List ID: " . config('activecampaign.lists.default', 5));
    $this->line("   Tag: " . config('activecampaign.tags.registro_nuevo', 'RegistroNuevo'));
    
    // Test de conexión
    $this->info("\n2. API Connection Test:");
    try {
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Api-Token' => config('activecampaign.api.token'),
        ])->get(config('activecampaign.api.base_url') . '/api/3/accounts');
        
        if ($response->successful()) {
            $data = $response->json();
            $this->info("   ✅ Connected successfully!");
            $this->line("   Account: " . ($data['account']['name'] ?? 'N/A'));
            $this->line("   Account ID: " . ($data['account']['accountid'] ?? 'N/A'));
        } else {
            $this->error("   ❌ Connection failed: " . $response->status());
        }
    } catch (\Exception $e) {
        $this->error("   ❌ Exception: " . $e->getMessage());
    }
    
    // Verificar usuarios
    $this->info("\n3. Database Check:");
    $userCount = User::count();
    $this->line("   Total users: " . $userCount);
    
    if ($userCount > 0) {
        $lastUser = User::latest()->first();
        $this->line("   Last user: " . $lastUser->email . " (ID: {$lastUser->id})");
    }
    
    $this->info("\n✅ Test completed!");
    
})->purpose('Test ActiveCampaign configuration and connection');

Artisan::command('activecampaign:sync {email?}', function ($email = null) {
    $service = app(ActiveCampaignService::class);
    
    // Obtener usuario
    if ($email) {
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error("User with email '{$email}' not found");
            return 1;
        }
    } else {
        $user = User::latest()->first();
        if (!$user) {
            $this->error("No users found in database");
            return 1;
        }
        $this->info("Using latest user: {$user->email}");
    }
    
    $this->info("📋 User details:");
    $this->table(
        ['Field', 'Value'],
        [
            ['ID', $user->id],
            ['Email', $user->email],
            ['Name', $user->name],
            ['Full Name', $user->full_name],
            ['Phone', $user->phone ?? 'N/A'],
            ['State', $user->state ?? 'N/A'],
        ]
    );
    
    if (!$this->confirm('Sync this user to ActiveCampaign?')) {
        return 0;
    }
    
    $this->info("🔄 Syncing...");
    
    try {
        $userData = [
            'email' => $user->email,
            'first_name' => $user->name,
            'last_name' => trim(($user->paternal_lastname ?? '') . ' ' . ($user->maternal_lastname ?? '')),
            'phone' => $user->phone,
        ];
        
        $listId = config('activecampaign.lists.default', 5);
        $tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo')];
        
        $result = $service->syncContact($userData, $listId, $tags);
        
        if ($result['success']) {
            $this->info("✅ Sync successful!");
            $this->table(
                ['Result', 'Value'],
                [
                    ['Contact ID', $result['contact_id']],
                    ['Action', $result['action']],
                    ['List ID', $listId],
                    ['Tags', implode(', ', $tags)],
                ]
            );
        } else {
            $this->error("❌ Sync failed: " . $result['error']);
            return 1;
        }
        
    } catch (\Exception $e) {
        $this->error("❌ Exception: " . $e->getMessage());
        return 1;
    }
    
    return 0;
    
})->purpose('Sync a user to ActiveCampaign');

Artisan::command('activecampaign:queue-worker', function () {
    $this->info('Starting ActiveCampaign queue worker...');
    $this->line('Press Ctrl+C to stop');
    
    // Ejecutar queue worker
    $this->call('queue:work', [
        '--queue' => 'activecampaign',
        '--tries' => 3,
        '--timeout' => 60,
        '--sleep' => 3,
    ]);
    
})->purpose('Start queue worker for ActiveCampaign jobs');


Artisan::command('ac:diagnose', function () {
    $this->info('🔍 ActiveCampaign Full Diagnosis');
    
    $issues = [];
    
    // 1. Config check
    $this->info("\n1. Configuration:");
    $requiredConfigs = [
        'activecampaign.api.base_url' => 'API Base URL',
        'activecampaign.api.token' => 'API Token',
        'activecampaign.sync.enabled' => 'Sync Enabled',
        'activecampaign.lists.default' => 'Default List ID',
    ];
    
    foreach ($requiredConfigs as $key => $label) {
        $value = config($key);
        $status = $value ? '✅' : '❌';
        $this->line("   {$status} {$label}: " . ($value ?: 'NOT SET'));
        
        if (!$value && $key !== 'activecampaign.sync.enabled') {
            $issues[] = "Missing config: {$key}";
        }
    }
    
    // 2. API Test
    $this->info("\n2. API Connection Test:");
    try {
        $response = Http::withHeaders([
            'Api-Token' => config('activecampaign.api.token'),
        ])->timeout(10)->get(config('activecampaign.api.base_url') . '/api/3/accounts');
        
        if ($response->successful()) {
            $this->line("   ✅ API Connection: SUCCESS");
            $data = $response->json();
            $this->line("      Account: " . ($data['account']['name'] ?? 'N/A'));
        } else {
            $this->line("   ❌ API Connection: FAILED - Status " . $response->status());
            $issues[] = "API connection failed: " . $response->status();
        }
    } catch (Exception $e) {
        $this->line("   ❌ API Connection: ERROR - " . $e->getMessage());
        $issues[] = "API exception: " . $e->getMessage();
    }
    
    // 3. Queue System
    $this->info("\n3. Queue System:");
    try {
        $jobsCount = DB::table('jobs')->where('queue', 'activecampaign')->count();
        $failedCount = DB::table('failed_jobs')->where('queue', 'activecampaign')->count();
        
        $this->line("   ActiveCampaign jobs pending: {$jobsCount}");
        $this->line("   ActiveCampaign jobs failed: {$failedCount}");
        
        if ($failedCount > 0) {
            $issues[] = "{$failedCount} failed jobs in queue";
        }
    } catch (Exception $e) {
        $this->line("   ❌ Queue check failed: " . $e->getMessage());
    }
    
    // 4. Event System
    $this->info("\n4. Event System:");
    try {
        $hasEvent = class_exists(\App\Events\UserRegistered::class);
        $hasListener = class_exists(\App\Listeners\SyncUserToActiveCampaignListener::class);
        
        $this->line("   Event UserRegistered: " . ($hasEvent ? '✅' : '❌'));
        $this->line("   Listener SyncUser...: " . ($hasListener ? '✅' : '❌'));
        
        if (!$hasEvent) $issues[] = "UserRegistered event class missing";
        if (!$hasListener) $issues[] = "SyncUserToActiveCampaignListener class missing";
    } catch (Exception $e) {
        $this->line("   ❌ Event check failed: " . $e->getMessage());
    }
    
    // 5. Summary
    $this->info("\n5. Diagnosis Summary:");
    
    if (empty($issues)) {
        $this->line("   🎉 All systems operational!");
        $this->line("   Next steps:");
        $this->line("   1. Run: php artisan activecampaign:queue-worker");
        $this->line("   2. Register a user from your website");
        $this->line("   3. Check ActiveCampaign contacts");
    } else {
        $this->line("   ⚠️ Found " . count($issues) . " issue(s):");
        foreach ($issues as $issue) {
            $this->line("   • {$issue}");
        }
    }
    
    $this->info("\n✅ Diagnosis complete");
})->purpose('Full diagnosis of ActiveCampaign integration');

Artisan::command('activecampaign:list-fields', function () {
    $this->info('📋 Listing ActiveCampaign custom fields');
    
    try {
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Api-Token' => config('activecampaign.api.token'),
        ])->get(config('activecampaign.api.base_url') . '/api/3/fields');
        
        if ($response->successful()) {
            $data = $response->json();
            $fields = $data['fields'] ?? [];
            
            if (empty($fields)) {
                $this->info('No custom fields found in ActiveCampaign');
                return;
            }
            
            $this->info("Found " . count($fields) . " custom fields:");
            
            $tableData = [];
            foreach ($fields as $field) {
                $tableData[] = [
                    'ID' => $field['id'],
                    'Title' => $field['title'],
                    'Type' => $field['type'],
                    'Personalization Tag' => $field['perstag'],
                    'Description' => $field['description'] ?? '',
                ];
            }
            
            $this->table(
                ['ID', 'Title', 'Type', 'Personalization Tag', 'Description'],
                $tableData
            );
            
            $this->info("\n📝 For .env file:");
            foreach ($fields as $field) {
                // Crear nombre de variable basado en el título
                $envName = 'ACTIVE_CAMPAIGN_FIELD_' . strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $field['perstag']));
                $this->line("{$envName}={$field['id']}  # {$field['title']}");
            }
            
        } else {
            $this->error("Failed to fetch fields: " . $response->status());
            $this->line("Response: " . $response->body());
        }
        
    } catch (\Exception $e) {
        $this->error("Exception: " . $e->getMessage());
    }
})->purpose('List all custom fields from ActiveCampaign');

Artisan::command('activecampaign:find-field {search}', function ($search) {
    $this->info("🔍 Searching for field: {$search}");
    
    try {
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Api-Token' => config('activecampaign.api.token'),
        ])->get(config('activecampaign.api.base_url') . '/api/3/fields');
        
        if ($response->successful()) {
            $data = $response->json();
            $fields = $data['fields'] ?? [];
            
            $foundFields = [];
            foreach ($fields as $field) {
                if (stripos($field['title'], $search) !== false || 
                    stripos($field['perstag'], $search) !== false ||
                    stripos($field['description'] ?? '', $search) !== false) {
                    $foundFields[] = $field;
                }
            }
            
            if (empty($foundFields)) {
                $this->warn("No fields found matching '{$search}'");
                $this->line("\nAll available fields:");
                foreach ($fields as $field) {
                    $this->line("  • [{$field['id']}] {$field['title']} ({$field['perstag']})");
                }
                return;
            }
            
            $this->info("Found " . count($foundFields) . " matching field(s):");
            
            $tableData = [];
            foreach ($foundFields as $field) {
                $tableData[] = [
                    'ID' => $field['id'],
                    'Title' => $field['title'],
                    'Type' => $field['type'],
                    'Personalization Tag' => $field['perstag'],
                    'Created' => isset($field['cdate']) ? date('Y-m-d', strtotime($field['cdate'])) : 'N/A',
                ];
            }
            
            $this->table(
                ['ID', 'Title', 'Type', 'Personalization Tag', 'Created'],
                $tableData
            );
            
        } else {
            $this->error("Failed to fetch fields: " . $response->status());
        }
        
    } catch (\Exception $e) {
        $this->error("Exception: " . $e->getMessage());
    }
})->purpose('Search for a specific field in ActiveCampaign');


Artisan::command('activecampaign:contact-fields {email}', function ($email) {
    $this->info("📊 Checking fields for contact: {$email}");
    
    try {
        // 1. Buscar el contacto
        $contactResponse = \Illuminate\Support\Facades\Http::withHeaders([
            'Api-Token' => config('activecampaign.api.token'),
        ])->get(config('activecampaign.api.base_url') . '/api/3/contacts', [
            'email' => $email
        ]);
        
        if (!$contactResponse->successful()) {
            $this->error("Failed to find contact: " . $contactResponse->status());
            return;
        }
        
        $contactData = $contactResponse->json();
        $contacts = $contactData['contacts'] ?? [];
        
        if (empty($contacts)) {
            $this->error("Contact not found: {$email}");
            return;
        }
        
        $contactId = $contacts[0]['id'];
        $this->info("Contact ID: {$contactId}");
        
        // 2. Obtener todos los campos personalizados disponibles
        $fieldsResponse = \Illuminate\Support\Facades\Http::withHeaders([
            'Api-Token' => config('activecampaign.api.token'),
        ])->get(config('activecampaign.api.base_url') . '/api/3/fields');
        
        if (!$fieldsResponse->successful()) {
            $this->error("Failed to fetch fields: " . $fieldsResponse->status());
            return;
        }
        
        $fieldsData = $fieldsResponse->json();
        $allFields = $fieldsData['fields'] ?? [];
        
        // 3. Obtener valores de campos para este contacto
        $fieldValuesResponse = \Illuminate\Support\Facades\Http::withHeaders([
            'Api-Token' => config('activecampaign.api.token'),
        ])->get(config('activecampaign.api.base_url') . '/api/3/fieldValues', [
            'filters[contactid]' => $contactId,
        ]);
        
        $fieldValues = [];
        if ($fieldValuesResponse->successful()) {
            $valuesData = $fieldValuesResponse->json();
            $fieldValues = $valuesData['fieldValues'] ?? [];
        }
        
        // 4. Crear mapeo de ID de campo a valor
        $fieldValueMap = [];
        foreach ($fieldValues as $fieldValue) {
            $fieldValueMap[$fieldValue['field']] = $fieldValue['value'];
        }
        
        // 5. Mostrar tabla
        $this->info("\n📋 Custom fields for contact:");
        
        $tableData = [];
        foreach ($allFields as $field) {
            $value = $fieldValueMap[$field['id']] ?? '(empty)';
            $tableData[] = [
                'Field ID' => $field['id'],
                'Title' => $field['title'],
                'Personalization Tag' => $field['perstag'],
                'Value' => $value,
                'Type' => $field['type'],
            ];
        }
        
        $this->table(
            ['Field ID', 'Title', 'Personalization Tag', 'Value', 'Type'],
            $tableData
        );
        
        // 6. Mostrar para .env
        $this->info("\n📝 Field IDs for your .env file:");
        foreach ($allFields as $field) {
            $envName = 'ACTIVE_CAMPAIGN_FIELD_' . strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $field['perstag']));
            $this->line("{$envName}={$field['id']}  # {$field['title']}");
        }
        
    } catch (\Exception $e) {
        $this->error("Exception: " . $e->getMessage());
    }
})->purpose('Show custom fields for a specific contact');

Artisan::command('activecampaign:auto-configure', function () {
    $this->info('🤖 Auto-configuring ActiveCampaign fields');
    
    // Campos que necesitamos mapear
    $neededFields = [
        'Apellido Paterno' => 'paternal_lastname',
        'Apellido Materno' => 'maternal_lastname', 
        'Fecha de Nacimiento' => 'birth_date',
        'Género' => 'gender',
        'Estado México' => 'state',
        'País Teléfono' => 'phone_country',
        'Referido Por' => 'referred_by',
        'Fecha de Registro' => 'created_at',
        'Empresa' => 'company',
        'Teléfono' => 'phone',
    ];
    
    try {
        // Obtener todos los campos de ActiveCampaign
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Api-Token' => config('activecampaign.api.token'),
        ])->get(config('activecampaign.api.base_url') . '/api/3/fields');
        
        if (!$response->successful()) {
            $this->error("Failed to fetch fields: " . $response->status());
            return;
        }
        
        $data = $response->json();
        $acFields = $data['fields'] ?? [];
        
        $this->info("Found " . count($acFields) . " fields in ActiveCampaign");
        
        // Buscar coincidencias
        $matches = [];
        $unmatched = [];
        
        foreach ($neededFields as $spanishName => $dbField) {
            $found = false;
            
            foreach ($acFields as $acField) {
                $acTitle = strtolower($acField['title']);
                $searchTerm = strtolower($spanishName);
                
                // Buscar coincidencias parciales
                if (str_contains($acTitle, $searchTerm) || 
                    levenshtein($acTitle, $searchTerm) < 5) {
                    
                    $matches[$dbField] = [
                        'ac_id' => $acField['id'],
                        'ac_title' => $acField['title'],
                        'ac_perstag' => $acField['perstag'],
                        'ac_type' => $acField['type'],
                    ];
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $unmatched[] = $spanishName;
            }
        }
        
        // Mostrar resultados
        $this->info("\n✅ Matched fields:");
        if (empty($matches)) {
            $this->line("No matches found");
        } else {
            $tableData = [];
            foreach ($matches as $dbField => $acField) {
                $tableData[] = [
                    'Your Field' => $dbField,
                    'AC Field ID' => $acField['ac_id'],
                    'AC Title' => $acField['ac_title'],
                    'AC Type' => $acField['ac_type'],
                ];
            }
            $this->table(
                ['Your Field', 'AC Field ID', 'AC Title', 'AC Type'],
                $tableData
            );
        }
        
        if (!empty($unmatched)) {
            $this->info("\n❌ Fields not found in ActiveCampaign:");
            foreach ($unmatched as $field) {
                $this->line("  • {$field}");
            }
        }
        
        // Generar configuración
        $this->info("\n📝 Generated configuration for config/activecampaign.php:");
        $this->line("\n'field_mapping' => [");
        foreach ($matches as $dbField => $acField) {
            $this->line("    '{$dbField}' => env('ACTIVE_CAMPAIGN_FIELD_" . strtoupper($acField['ac_perstag']) . "', {$acField['ac_id']}),");
        }
        $this->line("],");
        
        $this->info("\n📝 For your .env file:");
        foreach ($matches as $dbField => $acField) {
            $envVar = 'ACTIVE_CAMPAIGN_FIELD_' . strtoupper($acField['ac_perstag']);
            $this->line("{$envVar}={$acField['ac_id']}");
        }
        
        $this->info("\n🎯 Next steps:");
        $this->line("1. Copy the .env variables above to your .env file");
        $this->line("2. Update field_mapping in config/activecampaign.php");
        $this->line("3. Run: php artisan config:clear");
        $this->line("4. Test with: php artisan activecampaign:test-fields");
        
    } catch (\Exception $e) {
        $this->error("Exception: " . $e->getMessage());
    }
})->purpose('Auto-discover and configure field mappings');

Artisan::command('activecampaign:full-test {email?}', function ($email = null) {
    $this->info('🧪 Full ActiveCampaign Integration Test');
    
    $service = app(ActiveCampaignService::class);
    
    // 1. Test connection usando endpoint de lists
    $this->info("\n1. Testing API connection...");
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->withHeaders([
                'Api-Token' => config('activecampaign.api.token'),
                'Accept' => 'application/json',
            ])
            ->get(config('activecampaign.api.base_url') . '/api/3/lists?limit=1');
        
        if ($response->successful()) {
            $this->line("   ✅ API Connection: SUCCESS");
            $data = $response->json();
            $listCount = count($data['lists'] ?? []);
            $this->line("   Lists available: {$listCount}");
        } else {
            $this->error("   ❌ API Connection: FAILED - Status {$response->status()}");
            $this->line("   Error: " . $response->body());
            return 1;
        }
        
    } catch (\Exception $e) {
        $this->error("   ❌ API Connection: ERROR - " . $e->getMessage());
        return 1;
    }
    
    // 2. Get or select user
    if ($email) {
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error("User not found: {$email}");
            return 1;
        }
    } else {
        $user = User::latest()->first();
        if (!$user) {
            $this->error("No users found");
            return 1;
        }
        $this->info("Using latest user: {$user->email}");
    }
    
    // 3. Show user data
    $this->info("\n2. User Data:");
    $this->table(
        ['Field', 'Value'],
        [
            ['ID', $user->id],
            ['Email', $user->email],
            ['Name', $user->name],
            ['Paternal Lastname', $user->paternal_lastname ?? 'N/A'],
            ['Maternal Lastname', $user->maternal_lastname ?? 'N/A'],
            ['Phone', $user->phone ?? 'N/A'],
            ['Phone Country', $user->phone_country ?? 'N/A'],
            ['Birth Date', $user->birth_date?->format('Y-m-d') ?? 'N/A'],
            //['Gender', $user->gender?->value . ' (' . $service->mapGenderToSpanish($user->gender?->value) . ')' ?? 'N/A'],
            ['State', $user->state ?? 'N/A'],
            ['Referred By', $user->referred_by ?? 'N/A'],
            ['Created At', $user->created_at->format('Y-m-d H:i:s')],
        ]
    );
    
    // 4. Prepare data
    $this->info("\n3. Preparing data for ActiveCampaign...");
    $userData = $service->prepareUserData($user);
    
    $this->info("   Basic contact data:");
    $this->table(
        ['Field', 'Value'],
        [
            ['email', $userData['email']],
            ['first_name', $userData['first_name']],
            ['last_name', $userData['last_name']],
            ['phone', $userData['phone'] ?? 'N/A'],
        ]
    );
    
    if (isset($userData['custom_fields'])) {
        $this->info("   Custom fields to send:");
        $this->table(
            ['AC Field ID', 'Value'],
            array_map(function($id, $value) {
                return [$id, $value];
            }, array_keys($userData['custom_fields']), array_values($userData['custom_fields']))
        );
    } else {
        $this->warn("   No custom fields configured");
    }
    
    // 5. Check if contact exists
    $this->info("\n4. Checking existing contact...");
    $existingContact = $service->getContactByEmail($user->email);
    
    if ($existingContact) {
        $this->line("   ✅ Contact exists in ActiveCampaign");
        $this->line("      Contact ID: {$existingContact['id']}");
        $this->line("      Created: " . ($existingContact['created'] ?? 'N/A'));
    } else {
        $this->line("   ℹ️ Contact does not exist in ActiveCampaign (will be created)");
    }
    
    if ($this->confirm("\nPerform full sync test?")) 
    {
        $this->info(" \n5. Performing sync...");
        
        $listId = config('activecampaign.lists.default', 5);
        $tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo')];
        $customFields = $userData['custom_fields'] ?? [];
        
        $result = $service->syncContactWithCustomFields($userData, $listId, $tags, $customFields);
        
        if ($result['success']) {
            $this->info("   ✅ Sync successful!");
            $this->table(
                ['Result', 'Value'],
                [
                    ['Contact ID', $result['contact_id']],
                    ['Action', $result['action']],
                    ['List ID', $listId],
                    ['Tags', implode(', ', $tags)],
                    ['Custom Fields', count($customFields)],
                ]
            );
            
            // Show URL
            $baseUrl = str_replace('/api/3', '', config('activecampaign.api.base_url'));
            $this->info("\n🔗 View in ActiveCampaign:");
            $this->line("   {$baseUrl}/app/contacts/{$result['contact_id']}");
            
            // Optional: show updated contact
            if ($this->confirm('Show updated contact details?')) {
                $this->call('activecampaign:contact-fields', ['email' => $user->email]);
            }
            
        } else {
            $this->error("   ❌ Sync failed: {$result['error']}");
            return 1;
        }
    }
    
    $this->info("\n✅ Test completed successfully!");
    return 0;
})->purpose('Full integration test with custom fields');


Artisan::command('activecampaign:debug', function () {
    $this->info('🔍 Debugging ActiveCampaign Connection');
    
    // 1. Verificar configuración
    $this->info("\n1. Configuration Check:");
    $configs = [
        'ACTIVE_CAMPAIGN_API_BASE_URL' => env('ACTIVE_CAMPAIGN_API_BASE_URL'),
        'ACTIVE_CAMPAIGN_API_TOKEN (first 10 chars)' => env('ACTIVE_CAMPAIGN_API_TOKEN') ? substr(env('ACTIVE_CAMPAIGN_API_TOKEN'), 0, 10) . '...' : 'NOT SET',
        'ACTIVE_CAMPAIGN_SYNC_ENABLED' => env('ACTIVE_CAMPAIGN_SYNC_ENABLED'),
    ];
    
    foreach ($configs as $key => $value) {
        $status = $value ? '✅' : '❌';
        $this->line("   {$status} {$key}: {$value}");
    }
    
    // 2. Probar conexión manual con curl
    $this->info("\n2. Manual API Test:");
    
    $baseUrl = env('ACTIVE_CAMPAIGN_API_BASE_URL');
    $token = env('ACTIVE_CAMPAIGN_API_TOKEN');
    
    if (!$baseUrl || !$token) {
        $this->error("   Missing required configuration");
        return;
    }
    
    $this->line("   Testing URL: {$baseUrl}/api/3/accounts");
    
    try {
        // Usar Guzzle directamente para mejor debugging
        $client = new \GuzzleHttp\Client([
            'timeout' => 10,
            'verify' => false, // Temporalmente desactivar SSL verify para debug
        ]);
        
        $response = $client->get("{$baseUrl}/api/3/accounts", [
            'headers' => [
                'Api-Token' => $token,
                'Accept' => 'application/json',
            ],
        ]);
        
        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        
        $this->line("   ✅ HTTP Status: {$status}");
        
        $data = json_decode($body, true);
        if ($data && isset($data['account'])) {
            $this->line("   Account Name: " . ($data['account']['name'] ?? 'N/A'));
            $this->line("   Account ID: " . ($data['account']['accountid'] ?? 'N/A'));
        }
        
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        $this->error("   ❌ Request Exception:");
        $this->line("      Message: " . $e->getMessage());
        
        if ($e->hasResponse()) {
            $response = $e->getResponse();
            $this->line("      Status: " . $response->getStatusCode());
            $this->line("      Body: " . $response->getBody()->getContents());
        }
        
    } catch (\Exception $e) {
        $this->error("   ❌ General Exception: " . $e->getMessage());
    }
    
    // 3. Probar con Http facade (la que usa tu servicio)
    $this->info("\n3. Testing with Laravel Http Facade:");
    
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->withHeaders([
                'Api-Token' => $token,
                'Accept' => 'application/json',
            ])
            ->get("{$baseUrl}/api/3/accounts");
        
        $this->line("   Status: " . $response->status());
        $this->line("   Success: " . ($response->successful() ? 'Yes' : 'No'));
        
        if ($response->successful()) {
            $this->line("   ✅ Connection successful!");
        } else {
            $this->error("   Response body: " . $response->body());
        }
        
    } catch (\Exception $e) {
        $this->error("   ❌ Exception: " . $e->getMessage());
    }
    
    // 4. Verificar URL específica
    $this->info("\n4. URL Verification:");
    $this->line("   Your base URL: {$baseUrl}");
    
    // Mostrar cómo debería verse la URL completa
    $this->line("   Full endpoint should be: {$baseUrl}/api/3/accounts");
    
    // 5. Verificar permisos de API key
    $this->info("\n5. API Key Permissions Check:");
    $this->line("   Make sure your API key has at least:");
    $this->line("   • Contact/Account: Read & Write");
    $this->line("   • Lists: Read & Write");
    $this->line("   • Tags: Read & Write");
    $this->line("   • Custom Fields: Read & Write");
    
    $this->info("\n🎯 Next steps:");
    $this->line("1. Verify API token in ActiveCampaign → Settings → Developer → API");
    $this->line("2. Check that the API key is not expired or revoked");
    $this->line("3. Verify the base URL format: https://YOUR_ACCOUNT.api-us1.com");
    $this->line("4. Try generating a new API key");
    
})->purpose('Debug ActiveCampaign connection issues');

Artisan::command('activecampaign:quick-test', function () {
    $this->info('⚡ Quick ActiveCampaign Test');
    
    $baseUrl = config('activecampaign.api.base_url');
    $token = config('activecampaign.api.token');
    
    $this->line("Base URL: {$baseUrl}");
    $this->line("Token: " . ($token ? substr($token, 0, 10) . '...' : 'NOT SET'));
    
    // Test con endpoint de contacts (disponible en todos los planes)
    $this->info("\nTesting with /api/3/contacts endpoint:");
    
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->withHeaders([
                'Api-Token' => $token,
                'Accept' => 'application/json',
            ])
            ->get("{$baseUrl}/api/3/contacts?limit=1");
        
        $status = $response->status();
        $success = $response->successful();
        
        $this->line("Status: {$status}");
        $this->line("Success: " . ($success ? '✅ Yes' : '❌ No'));
        
        if ($success) {
            $data = $response->json();
            $contactCount = $data['meta']['total'] ?? 0;
            $this->line("Total contacts in account: {$contactCount}");
            $this->info("\n🎉 API Connection is WORKING!");
        } else {
            $this->error("Error: " . $response->body());
        }
        
    } catch (\Exception $e) {
        $this->error("Exception: " . $e->getMessage());
    }
    
    // Test de lista específica
    $listId = config('activecampaign.lists.default', 5);
    $this->info("\nTesting list ID {$listId}:");
    
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->withHeaders([
                'Api-Token' => $token,
                'Accept' => 'application/json',
            ])
            ->get("{$baseUrl}/api/3/lists/{$listId}");
        
        if ($response->successful()) {
            $data = $response->json();
            $this->line("✅ List exists: " . ($data['list']['name'] ?? 'Unknown'));
        } else {
            $this->warn("⚠️ List not found or access denied");
        }
        
    } catch (\Exception $e) {
        $this->error("Error checking list: " . $e->getMessage());
    }
    
    $this->info("\n✅ Quick test completed");
})->purpose('Quick test of ActiveCampaign API');

Artisan::command('activecampaign:check-endpoints', function () {
    $this->info('🔌 Checking essential API endpoints');
    
    $baseUrl = config('activecampaign.api.base_url');
    $token = config('activecampaign.api.token');
    
    $essentialEndpoints = [
        ['/api/3/contacts', 'Contacts', 'Needed for creating/updating contacts'],
        ['/api/3/lists', 'Lists', 'Needed for adding contacts to lists'],
        ['/api/3/tags', 'Tags', 'Needed for tagging contacts'],
        ['/api/3/fields', 'Custom Fields', 'Needed for custom field data'],
        ['/api/3/fieldValues', 'Field Values', 'Needed for updating custom fields'],
    ];
    
    $results = [];
    
    foreach ($essentialEndpoints as $endpoint) {
        [$url, $name, $description] = $endpoint;
        
        $this->info("\nTesting: {$name}");
        $this->line("Endpoint: {$url}");
        $this->line("Purpose: {$description}");
        
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'Api-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->get("{$baseUrl}{$url}?limit=1");
            
            $status = $response->status();
            $success = $response->successful();
            
            $results[] = [
                'Endpoint' => $name,
                'Status' => $status,
                'Available' => $success ? '✅ Yes' : '❌ No',
                'Notes' => $success ? 'Working' : ($status === 403 ? 'Check permissions' : 'Check endpoint'),
            ];
            
            $this->line("Status: {$status}");
            $this->line("Available: " . ($success ? '✅ Yes' : '❌ No'));
            
            if (!$success) {
                $this->warn("Response: " . $response->body());
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $results[] = [
                'Endpoint' => $name,
                'Status' => 'ERROR',
                'Available' => '❌ No',
                'Notes' => $e->getMessage(),
            ];
        }
    }
    
    $this->info("\n📊 Summary:");
    $this->table(
        ['Endpoint', 'Status', 'Available', 'Notes'],
        $results
    );
    
    $workingCount = count(array_filter($results, fn($r) => str_contains($r['Available'], '✅')));
    $totalCount = count($results);
    
    if ($workingCount === $totalCount) {
        $this->info("\n🎉 All essential endpoints are available!");
    } else {
        $this->warn("\n⚠️ {$workingCount}/{$totalCount} endpoints available");
        $this->line("Some endpoints may not be available in your plan.");
        $this->line("Contact ActiveCampaign support or upgrade your plan if needed.");
    }
})->purpose('Check availability of essential API endpoints');

Artisan::command('ac:debug-user-simple {email}', function ($email) {
    $this->info("🔍 Debugging user: {$email}");
    
    $service = app(ActiveCampaignService::class);
    $user = User::where('email', $email)->first();
    
    if (!$user) {
        $this->error("User not found");
        return;
    }
    
    // 1. Mostrar datos del usuario de forma simple
    $this->info("\n1. User data (raw):");
    echo "Name: " . $user->name . "\n";
    echo "Paternal Lastname: " . ($user->paternal_lastname ?? 'NULL') . "\n";
    echo "Maternal Lastname: " . ($user->maternal_lastname ?? 'NULL') . "\n";
    echo "Email: " . $user->email . "\n";
    echo "Phone: " . ($user->phone ?? 'NULL') . "\n";
    echo "Phone Country: " . ($user->phone_country ?? 'NULL') . "\n";
    echo "Birth Date: " . ($user->birth_date?->format('Y-m-d') ?? 'NULL') . "\n";
    echo "Gender: " . ($user->gender?->value ?? 'NULL') . "\n";
    echo "State: " . ($user->state ?? 'NULL') . "\n";
    echo "Referred By: " . ($user->referred_by ?? 'NULL') . "\n";
    echo "Created At: " . ($user->created_at?->format('Y-m-d H:i:s') ?? 'NULL') . "\n";
    
    // 2. Mostrar mapeo de campos
    $this->info("\n2. Field mapping from config:");
    $mapping = config('activecampaign.field_mapping', []);
    
    if (empty($mapping)) {
        $this->error("No field mapping configured!");
        return;
    }
    
    foreach ($mapping as $field => $fieldId) {
        echo "{$field} => AC Field ID: " . ($fieldId ?: 'NOT SET') . "\n";
    }
    
    // 3. Usar prepareCustomFields para ver qué campos se preparan
    $this->info("\n3. Testing prepareCustomFields method:");
    
    try {
        $customFields = $service->prepareCustomFields($user);
        
        if (empty($customFields)) {
            $this->error("❌ prepareCustomFields returned empty array!");
            
            // Debug más detallado
            $this->info("\nDebugging why fields are empty:");
            foreach ($mapping as $field => $fieldId) {
                if (!$fieldId) {
                    echo "Field '{$field}': SKIP (no AC field ID)\n";
                    continue;
                }
                
                $value = $user->{$field} ?? null;
                echo "Field '{$field}': DB Value = " . ($value ?? 'NULL') . "\n";
            }
        } else {
            $this->info("✅ prepareCustomFields returned " . count($customFields) . " fields:");
            foreach ($customFields as $fieldId => $value) {
                echo "AC Field ID {$fieldId}: '{$value}'\n";
            }
        }
        
    } catch (\Exception $e) {
        $this->error("Exception in prepareCustomFields: " . $e->getMessage());
    }
    
    // 4. Verificar contacto en ActiveCampaign
    $this->info("\n4. Checking ActiveCampaign contact:");
    $contact = $service->getContactByEmail($email);
    
    if ($contact) {
        echo "✅ Contact exists in AC. ID: " . $contact['id'] . "\n";
        
        // Verificar campos actuales
        $this->info("\n5. Current field values in ActiveCampaign:");
        
        // Para cada campo mapeado, verificar su valor actual
        foreach ($mapping as $field => $fieldId) {
            if (!$fieldId) continue;
            
            $fieldValue = $service->getFieldValue($contact['id'], $fieldId);
            $currentValue = $fieldValue['value'] ?? '(empty)';
            
            echo "Field ID {$fieldId} ({$field}): '{$currentValue}'\n";
        }
    } else {
        echo "ℹ️ Contact not found in ActiveCampaign\n";
    }
    
    $this->info("\n✅ Debug complete");
})->purpose('Simple debug for user data and field mapping');


Artisan::command('ac:debug-listener {email}', function ($email) {
    $this->info("🔍 Debugging listener for user: {$email}");
    
    $user = User::where('email', $email)->first();
    
    if (!$user) {
        $this->error("User not found");
        return;
    }
    
    // Simular lo que hace el listener
    $service = app(ActiveCampaignService::class);
    
    $this->info("\n1. Testing prepareUserData():");
    $userData = $service->prepareUserData($user);
    
    $this->table(
        ['Field', 'Value'],
        [
            ['email', $userData['email']],
            ['first_name', $userData['first_name']],
            ['last_name', $userData['last_name']],
            ['phone', $userData['phone'] ?? 'N/A'],
            ['Has custom fields', isset($userData['custom_fields']) ? '✅ Yes' : '❌ No'],
        ]
    );
    
    if (isset($userData['custom_fields'])) {
        $this->info("\n2. Custom fields prepared:");
        $this->table(
            ['Field ID', 'Value'],
            array_map(function($id, $value) {
                return [$id, $value];
            }, array_keys($userData['custom_fields']), array_values($userData['custom_fields']))
        );
        
        $this->info("\n3. Field mapping used:");
        $mapping = config('activecampaign.field_mapping', []);
        foreach ($mapping as $field => $fieldId) {
            $value = $user->{$field} ?? null;
            
            // Manejar el caso específico del Enum Gender
            if ($field === 'gender' && $value instanceof \App\Enums\Gender) {
                $value = $value->value . ' (' . $service->mapGenderToSpanish($value->value) . ')';
            }
            
            // Manejar fechas
            if ($value instanceof \Carbon\Carbon) {
                $value = $value->format('Y-m-d');
            }
            
            $status = $fieldId ? '✅' : '❌';
            $this->line("{$status} {$field} => AC ID: {$fieldId}, DB Value: " . ($value ?? 'NULL'));
        }
    } else {
        $this->error("\n2. No custom fields prepared!");
        
        $this->info("\nChecking why:");
        $mapping = config('activecampaign.field_mapping', []);
        
        if (empty($mapping)) {
            $this->error("Field mapping is empty in config!");
            $this->line("Check config/activecampaign.php");
        } else {
            $this->info("Field mapping exists but may not match user data:");
            foreach ($mapping as $field => $fieldId) {
                $value = $user->{$field} ?? null;
                
                // Manejar el caso específico del Enum Gender
                if ($field === 'gender' && $value instanceof \App\Enums\Gender) {
                    $value = $value->value . ' (' . $service->mapGenderToSpanish($value->value) . ')';
                }
                
                $this->line("{$field} => AC ID: {$fieldId}, DB Value: " . ($value ?? 'NULL'));
            }
        }
    }
    
    // Test de sincronización completa
    if ($this->confirm('\nTest full synchronization?')) {
        $this->info("\n4. Testing syncContactWithCustomFields...");
        
        $listId = config('activecampaign.lists.default', 5);
        $tags = ['RegistroNuevo', 'Paciente'];
        $customFields = $userData['custom_fields'] ?? [];
        
        $result = $service->syncContactWithCustomFields(
            [
                'email' => $userData['email'],
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'phone' => $userData['phone'] ?? null,
            ],
            $listId,
            $tags,
            $customFields
        );
        
        if ($result['success']) {
            $this->info("✅ Sync successful!");
            $this->table(
                ['Result', 'Value'],
                [
                    ['Contact ID', $result['contact_id']],
                    ['Action', $result['action']],
                    ['Custom Fields Sent', count($customFields)],
                ]
            );
        } else {
            $this->error("❌ Sync failed: " . $result['error']);
        }
    }
    
    $this->info("\n✅ Debug complete");
})->purpose('Debug what the listener should be doing');

Artisan::command('ac:show-job', function () {
    $this->info('📄 Showing SyncUserToActiveCampaign Job content');
    
    $jobPath = app_path('Jobs/SyncUserToActiveCampaign.php');
    
    if (!file_exists($jobPath)) {
        $this->error('Job file does not exist!');
        $this->line('Path: ' . $jobPath);
        return;
    }
    
    $content = file_get_contents($jobPath);
    
    $this->info('File exists. Here are the first 50 lines:');
    $lines = explode("\n", $content);
    
    for ($i = 0; $i < min(50, count($lines)); $i++) {
        $lineNumber = $i + 1;
        $this->line("{$lineNumber}: {$lines[$i]}");
    }
    
    // Verificar si usa syncContact o syncContactWithCustomFields
    if (strpos($content, 'syncContact(') !== false) {
        $this->warn("\n⚠️  Job is using syncContact() instead of syncContactWithCustomFields()!");
        $this->line('This method does NOT send custom fields.');
    } elseif (strpos($content, 'syncContactWithCustomFields(') !== false) {
        $this->info("\n✅ Job is using syncContactWithCustomFields()");
    } else {
        $this->error("\n❌ Job does not call any sync method!");
    }
    
    // Verificar si llama a prepareUserData
    if (strpos($content, 'prepareUserData(') !== false) {
        $this->info("✅ Job calls prepareUserData()");
    } else {
        $this->warn("⚠️  Job does NOT call prepareUserData()");
    }
})->purpose('Show SyncUserToActiveCampaign Job content and check for issues');

Artisan::command('ac:debug-job {email}', function ($email) {
    $this->info("🔍 Debugging SyncUserToActiveCampaign job for user: {$email}");
    
    $user = User::where('email', $email)->first();
    
    if (!$user) {
        $this->error("User not found");
        return;
    }
    
    // Simular el job
    $service = app(ActiveCampaignService::class);
    
    $this->info("1. Calling prepareUserData...");
    $userData = $service->prepareUserData($user);
    
    $this->table(
        ['Field', 'Value'],
        [
            ['email', $userData['email']],
            ['first_name', $userData['first_name']],
            ['last_name', $userData['last_name']],
            ['phone', $userData['phone'] ?? 'N/A'],
            ['Has custom fields', isset($userData['custom_fields']) ? '✅ Yes' : '❌ No'],
        ]
    );
    
    if (isset($userData['custom_fields'])) {
        $this->info("\n2. Custom fields prepared:");
        $this->table(
            ['Field ID', 'Value'],
            array_map(function($id, $value) {
                return [$id, $value];
            }, array_keys($userData['custom_fields']), array_values($userData['custom_fields']))
        );
        
        $this->info("\n3. Simulating syncContactWithCustomFields...");
        
        $listId = config('activecampaign.lists.default', 5);
        $tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo')];
        
        $result = $service->syncContactWithCustomFields(
            [
                'email' => $userData['email'],
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'phone' => $userData['phone'] ?? null,
            ],
            $listId,
            $tags,
            $userData['custom_fields']
        );
        
        if ($result['success']) {
            $this->info("✅ Sync successful!");
            $this->table(
                ['Result', 'Value'],
                [
                    ['Contact ID', $result['contact_id']],
                    ['Action', $result['action']],
                    ['Custom Fields Sent', count($userData['custom_fields'])],
                ]
            );
        } else {
            $this->error("❌ Sync failed: " . $result['error']);
        }
    } else {
        $this->error("No custom fields found in user data!");
    }
})->purpose('Debug the SyncUserToActiveCampaign job for a user');

Artisan::command('ac:debug-detailed {email}', function ($email) {
    $this->info("🔍 Detailed debugging for: {$email}");
    
    $user = User::where('email', $email)->first();
    
    if (!$user) {
        $this->error("User not found");
        return;
    }
    
    $service = app(ActiveCampaignService::class);
    
    // 1. Verificar datos del usuario en la base de datos
    $this->info("\n1. User database data:");
    $userData = [
        'id' => $user->id,
        'name' => $user->name,
        'paternal_lastname' => $user->paternal_lastname,
        'maternal_lastname' => $user->maternal_lastname,
        'email' => $user->email,
        'phone' => $user->phone,
        'birth_date' => $user->birth_date ? $user->birth_date->format('Y-m-d') : null,
        'gender' => $user->gender ? $user->gender->value . ' (' . $service->mapGenderToSpanish($user->gender->value) . ')' : null,
        'state' => $user->state,
        'phone_country' => $user->phone_country,
        'referred_by' => $user->referred_by,
        'created_at' => $user->created_at->format('Y-m-d H:i:s'),
    ];
    
    $this->table(
        ['Field', 'Value'],
        array_map(function($key, $value) {
            return [$key, $value ?? 'NULL'];
        }, array_keys($userData), array_values($userData))
    );
    
    // 2. Verificar configuración de mapeo
    $this->info("\n2. Field mapping configuration:");
    $fieldMapping = config('activecampaign.field_mapping', []);
    
    if (empty($fieldMapping)) {
        $this->error("❌ Field mapping is EMPTY!");
        $this->line("Check config/activecampaign.php");
    } else {
        $this->table(
            ['Database Field', 'AC Field ID'],
            array_map(function($field, $fieldId) {
                return [$field, $fieldId ?: 'NOT SET'];
            }, array_keys($fieldMapping), array_values($fieldMapping))
        );
    }
    
    // 3. Probar prepareCustomFields directamente
    $this->info("\n3. Testing prepareCustomFields directly:");
    
    try {
        $customFields = $service->prepareCustomFields($user);
        
        if (empty($customFields)) {
            $this->error("❌ prepareCustomFields returned EMPTY array!");
            
            // Debug detallado de por qué está vacío
            $this->info("\nDebugging each field:");
            foreach ($fieldMapping as $field => $fieldId) {
                if (!$fieldId) {
                    $this->line("{$field}: SKIP (no AC field ID)");
                    continue;
                }
                
                $value = $user->{$field} ?? null;
                
                // Manejar casos especiales
                if ($field === 'gender' && $value instanceof \App\Enums\Gender) {
                    $value = $value->value . ' -> ' . $service->mapGenderToSpanish($value->value);
                } elseif ($value instanceof \Carbon\Carbon) {
                    $value = $value->format('Y-m-d');
                }
                
                $this->line("{$field} (AC ID: {$fieldId}): " . ($value ?? 'NULL'));
            }
        } else {
            $this->info("✅ prepareCustomFields returned " . count($customFields) . " fields:");
            $this->table(
                ['AC Field ID', 'Value'],
                array_map(function($id, $value) {
                    return [$id, $value];
                }, array_keys($customFields), array_values($customFields))
            );
        }
        
    } catch (\Exception $e) {
        $this->error("❌ Exception in prepareCustomFields: " . $e->getMessage());
        $this->line("Trace: " . $e->getTraceAsString());
    }
    
    // 4. Probar prepareUserData
    $this->info("\n4. Testing prepareUserData:");
    
    try {
        $userData = $service->prepareUserData($user);
        
        $this->table(
            ['Field', 'Value'],
            [
                ['email', $userData['email']],
                ['first_name', $userData['first_name']],
                ['last_name', $userData['last_name']],
                ['phone', $userData['phone'] ?? 'N/A'],
                ['Has custom_fields', isset($userData['custom_fields']) ? '✅ Yes' : '❌ No'],
            ]
        );
        
        if (isset($userData['custom_fields'])) {
            $this->info("custom_fields array has " . count($userData['custom_fields']) . " items");
        }
        
    } catch (\Exception $e) {
        $this->error("Exception: " . $e->getMessage());
    }
    
    // 5. Verificar si ya existe en ActiveCampaign y qué campos tiene
    $this->info("\n5. Checking existing ActiveCampaign contact:");
    
    $contact = $service->getContactByEmail($email);
    
    if ($contact) {
        $this->info("✅ Contact exists in AC. ID: {$contact['id']}");
        
        // Verificar qué campos personalizados tiene actualmente
        $this->call('activecampaign:contact-fields', ['email' => $email]);
    } else {
        $this->info("ℹ️ Contact not found in ActiveCampaign");
    }
    
    $this->info("\n✅ Debug complete");
})->purpose('Detailed debug of user data and field mapping');


Artisan::command('ac:check-config', function () {
    $this->info('⚙️ Checking ActiveCampaign configuration');
    
    // Verificar toda la configuración
    $configs = [
        'activecampaign.api.base_url' => config('activecampaign.api.base_url'),
        'activecampaign.api.token (first 10 chars)' => config('activecampaign.api.token') ? substr(config('activecampaign.api.token'), 0, 10) . '...' : null,
        'activecampaign.sync.enabled' => config('activecampaign.sync.enabled'),
        'activecampaign.sync.use_queue' => config('activecampaign.sync.use_queue'),
        'activecampaign.lists.default' => config('activecampaign.lists.default'),
        'activecampaign.tags.registro_nuevo' => config('activecampaign.tags.registro_nuevo'),
    ];
    
    $this->table(
        ['Configuration Key', 'Value'],
        array_map(function($key, $value) {
            $status = ($value !== null && $value !== false) ? '✅' : '❌';
            return [$key, "{$status} " . var_export($value, true)];
        }, array_keys($configs), array_values($configs))
    );
    
    // Verificar field mapping
    $this->info("\n📋 Field Mapping:");
    $fieldMapping = config('activecampaign.field_mapping', []);
    
    if (empty($fieldMapping)) {
        $this->error("❌ Field mapping is EMPTY!");
        $this->line("This is likely the problem!");
    } else {
        $this->table(
            ['Database Field', 'AC Field ID', 'Status'],
            array_map(function($field, $fieldId) {
                $status = $fieldId ? '✅' : '❌';
                return [$field, $fieldId ?: 'NOT SET', $status];
            }, array_keys($fieldMapping), array_values($fieldMapping))
        );
    }
    
    // Mostrar configuración completa del archivo
    $configPath = config_path('activecampaign.php');
    if (file_exists($configPath)) {
        $this->info("\n📄 Config file (activecampaign.php) content:");
        $content = file_get_contents($configPath);
        
        // Buscar la parte de field_mapping
        if (preg_match("/'field_mapping' => \[(.*?)\]/s", $content, $matches)) {
            $this->line("Field mapping found:");
            $this->line($matches[0]);
        } else {
            $this->error("No field_mapping found in config file!");
        }
    }
    
    $this->info("\n✅ Config check complete");
})->purpose('Check ActiveCampaign configuration');


Artisan::command('ac:check-permissions', function () {
    $this->info('🔐 Checking API permissions');
    
    $baseUrl = config('activecampaign.api.base_url');
    $token = config('activecampaign.api.token');
    
    // Probar diferentes endpoints para ver permisos
    $endpoints = [
        '/api/3/contacts' => 'Read contacts',
        '/api/3/fieldValues' => 'Read/Write field values',
        '/api/3/fields' => 'Read custom fields',
        '/api/3/lists' => 'Read lists',
        '/api/3/tags' => 'Read tags',
    ];
    
    foreach ($endpoints as $endpoint => $description) {
        $this->info("\nTesting: {$description}");
        $this->line("Endpoint: {$endpoint}");
        
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'Api-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->get($baseUrl . $endpoint . '?limit=1');
            
            $status = $response->status();
            
            if ($response->successful()) {
                $this->line("✅ Status {$status}: Has READ permission");
                
                // Probar escritura para fieldValues
                if ($endpoint === '/api/3/fieldValues') {
                    $this->line("Testing WRITE permission for fieldValues...");
                    
                    // Crear un contacto de prueba primero
                    $contactResponse = \Illuminate\Support\Facades\Http::withHeaders([
                        'Api-Token' => $token,
                        'Content-Type' => 'application/json',
                    ])->post($baseUrl . '/api/3/contacts', [
                        'contact' => [
                            'email' => 'test_permissions_' . time() . '@example.com',
                            'firstName' => 'Test',
                            'lastName' => 'Permissions',
                        ]
                    ]);
                    
                    if ($contactResponse->successful()) {
                        $contactData = $contactResponse->json();
                        $testContactId = $contactData['contact']['id'] ?? null;
                        
                        if ($testContactId) {
                            // Probar crear field value
                            $fieldValueResponse = \Illuminate\Support\Facades\Http::withHeaders([
                                'Api-Token' => $token,
                                'Content-Type' => 'application/json',
                            ])->post($baseUrl . '/api/3/fieldValues', [
                                'fieldValue' => [
                                    'contact' => $testContactId,
                                    'field' => 1, // Usar un campo que exista
                                    'value' => 'Test Value',
                                ]
                            ]);
                            
                            if ($fieldValueResponse->successful()) {
                                $this->line("✅ Has WRITE permission for fieldValues");
                            } else {
                                $this->error("❌ No WRITE permission for fieldValues: Status " . $fieldValueResponse->status());
                            }
                            
                            // Limpiar contacto de prueba
                            \Illuminate\Support\Facades\Http::withHeaders([
                                'Api-Token' => $token,
                            ])->delete($baseUrl . "/api/3/contacts/{$testContactId}");
                        }
                    }
                }
                
            } elseif ($status === 403) {
                $this->error("❌ Status 403: NO permission for {$description}");
            } else {
                $this->warn("⚠️  Status {$status}: Check endpoint/permissions");
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
    
    $this->info("\n✅ Permissions check complete");
})->purpose('Check API permissions');

Artisan::command('ac:test-sync-job {email}', function ($email) {
    $this->info("🧪 Testing SyncUserToActiveCampaign job flow for: {$email}");
    
    $user = User::where('email', $email)->first();
    
    if (!$user) {
        $this->error("User not found");
        return;
    }
    
    $service = app(ActiveCampaignService::class);
    
    // Simular lo que hace el Job
    $userData = $service->prepareUserData($user);
    $customFields = $userData['custom_fields'] ?? [];
    
    $this->info("Prepared custom fields: " . count($customFields));
    
    $listId = config('activecampaign.lists.default', 5);
    $tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo')];
    
    // Activar logging detallado
    \Log::debug('Testing syncContactWithCustomFields', [
        'email' => $userData['email'],
        'customFields' => $customFields,
    ]);
    
    $result = $service->syncContactWithCustomFields(
        [
            'email' => $userData['email'],
            'first_name' => $userData['first_name'],
            'last_name' => $userData['last_name'],
            'phone' => $userData['phone'] ?? null,
        ],
        $listId,
        $tags,
        $customFields
    );
    
    $this->info("Result: " . json_encode($result));
    
    // Ahora, verificar los campos en ActiveCampaign
    $this->call('activecampaign:contact-fields', ['email' => $email]);
})->purpose('Test the exact flow of the SyncUserToActiveCampaign job');

Artisan::command('ac:full-debug {email}', function ($email) {
    $this->info("🔍 FULL DEBUG for: {$email}");
    
    $user = User::where('email', $email)->first();
    
    if (!$user) {
        $this->error("User not found");
        return;
    }
    
    $service = app(ActiveCampaignService::class);
    
    // 1. Preparar datos
    $userData = $service->prepareUserData($user);
    $customFields = $userData['custom_fields'] ?? [];
    
    $this->info("Custom fields prepared: " . count($customFields));
    foreach ($customFields as $fieldId => $value) {
        $this->line("  Field ID {$fieldId}: '{$value}'");
    }
    
    // 2. Verificar contacto en AC
    $contact = $service->getContactByEmail($email);
    
    if (!$contact) {
        $this->error("Contact not found in ActiveCampaign");
        return;
    }
    
    $contactId = $contact['id'];
    $this->info("Contact ID in ActiveCampaign: {$contactId}");
    
    // 3. Probar cada campo individualmente
    $this->info("\n🔬 Testing each field individually:");
    
    $fieldMapping = config('activecampaign.field_mapping', []);
    $results = [];
    
    foreach ($customFields as $fieldId => $value) {
        $this->line("\nTesting Field ID: {$fieldId}");
        $this->line("Value: '{$value}'");
        
        // Encontrar qué campo de la base de datos corresponde
        $dbField = array_search($fieldId, $fieldMapping);
        $this->line("Database field: " . ($dbField ?: 'Unknown'));
        
        // Usar el método de debugging
        $result = $service->debugUpdateProcess($contactId, $fieldId, $value);
        
        $status = $result['success'] ? '✅' : '❌';
        $action = $result['action'] ?? 'failed';
        
        $this->line("Result: {$status} {$action}");
        
        if (!$result['success']) {
            $this->error("Error: " . ($result['error'] ?? 'Unknown'));
        }
        
        $results[] = [
            'Field ID' => $fieldId,
            'DB Field' => $dbField ?: '?',
            'Value' => $value,
            'Status' => $result['success'] ? 'Success' : 'Failed',
            'Action' => $result['action'] ?? 'N/A',
            'Error' => $result['error'] ?? '',
        ];
        
        // Pequeña pausa
        usleep(300000); // 0.3 segundos
    }
    
    // 4. Mostrar resultados
    $this->info("\n📊 Results Summary:");
    $this->table(
        ['Field ID', 'DB Field', 'Value', 'Status', 'Action', 'Error'],
        $results
    );
    
    $successCount = count(array_filter($results, fn($r) => $r['Status'] === 'Success'));
    $totalCount = count($results);
    
    $this->info("\nSuccess rate: {$successCount}/{$totalCount}");
    
    // 5. Verificar después
    if ($successCount > 0) {
        $this->info("\n🔄 Verifying after update...");
        sleep(2); // Esperar a que ActiveCampaign procese
        
        $this->call('activecampaign:contact-fields', ['email' => $email]);
    }
    
    $this->info("\n✅ Debug complete");
})->purpose('Full debug with individual field testing');

Artisan::command('ac:queue-status', function () {
    $this->info('📊 ActiveCampaign Queue Status');
    
    // Verificar configuración
    $this->info("\n1. Configuration:");
    $this->line("ACTIVE_CAMPAIGN_USE_QUEUE: " . (config('activecampaign.sync.use_queue') ? 'true' : 'false'));
    $this->line("QUEUE_CONNECTION: " . config('queue.default'));
    
    // Verificar jobs pendientes
    $this->info("\n2. Pending Jobs:");
    
    $pendingJobs = \DB::table('jobs')
        ->where('queue', 'activecampaign')
        ->orWhere('queue', 'like', '%activecampaign%')
        ->get();
    
    $this->line("Total pending jobs: " . $pendingJobs->count());
    
    if ($pendingJobs->count() > 0) {
        $this->table(
            ['ID', 'Queue', 'Attempts', 'Created At'],
            $pendingJobs->map(function($job) {
                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'attempts' => $job->attempts,
                    'created_at' => $job->created_at,
                ];
            })->toArray()
        );
    }
    
    // Verificar jobs fallidos
    $this->info("\n3. Failed Jobs:");
    
    $failedJobs = \DB::table('failed_jobs')
        ->where('queue', 'like', '%activecampaign%')
        ->get();
    
    $this->line("Total failed jobs: " . $failedJobs->count());
    
    if ($failedJobs->count() > 0) {
        $this->table(
            ['ID', 'Queue', 'Failed At', 'Exception'],
            $failedJobs->take(5)->map(function($job) {
                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                    'exception' => substr($job->exception, 0, 100) . '...',
                ];
            })->toArray()
        );
        
        if ($this->confirm('Retry failed jobs?')) {
            $this->call('queue:retry', ['ids' => 'all']);
        }
    }
    
    // Verificar si el worker está corriendo
    $this->info("\n4. Queue Worker Status:");
    $this->line("To start worker: php artisan queue:work --queue=activecampaign --tries=3 --timeout=60");
    $this->line("To process specific job: php artisan queue:work --queue=activecampaign --once");
    
    // Opción para procesar un job manualmente
    if ($pendingJobs->count() > 0 && $this->confirm('Process oldest pending job?')) {
        $oldestJob = $pendingJobs->first();
        $this->info("Processing job ID: {$oldestJob->id}");
        
        // Extraer el comando del payload
        $payload = json_decode($oldestJob->payload, true);
        $command = $payload['data']['command'] ?? null;
        
        if ($command) {
            // Intentar deserializar y ejecutar
            try {
                $job = unserialize($command);
                $job->handle(app(ActiveCampaignService::class));
                $this->info("✅ Job processed manually");
                
                // Eliminar de la cola
                \DB::table('jobs')->where('id', $oldestJob->id)->delete();
            } catch (\Exception $e) {
                $this->error("❌ Error processing job: " . $e->getMessage());
            }
        }
    }
    
    $this->info("\n✅ Queue status check complete");
})->purpose('Check and fix queue status for ActiveCampaign');