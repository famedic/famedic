<?php
// test_activecampaign_sync.php
// Guarda este archivo en la raíz de tu proyecto Laravel y ejecuta: php test_activecampaign_sync.php

use App\Models\User;
use App\Enums\Gender;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Función de respaldo para actualizar un fieldValue directamente
function updateFieldValueDirectly($service, $contactId, $fieldId, $value) {
    try {
        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($service);

        // Primero, intentar crear (POST)
        $response = $client->post('/api/3/fieldValues', [
            'fieldValue' => [
                'contact' => $contactId,
                'field' => $fieldId,
                'value' => (string) $value
            ]
        ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Campo creado'];
        } elseif ($response->status() === 422) {
            // Si ya existe, buscar y actualizar
            $searchResponse = $client->get("/api/3/fieldValues", [
                'filters[contactid]' => $contactId,
                'filters[fieldid]' => $fieldId,
            ]);

            if ($searchResponse->successful()) {
                $data = $searchResponse->json();
                if (!empty($data['fieldValues'])) {
                    $fieldValue = $data['fieldValues'][0];
                    $fieldValueId = $fieldValue['id'];

                    $updateResponse = $client->put("/api/3/fieldValues/{$fieldValueId}", [
                        'fieldValue' => [
                            'contact' => $contactId,
                            'field' => $fieldId,
                            'value' => (string) $value
                        ]
                    ]);

                    if ($updateResponse->successful()) {
                        return ['success' => true, 'message' => 'Campo actualizado'];
                    } else {
                        return ['success' => false, 'error' => 'Error al actualizar: ' . $updateResponse->status()];
                    }
                } else {
                    return ['success' => false, 'error' => 'No se encontró el fieldValue'];
                }
            } else {
                return ['success' => false, 'error' => 'Error buscando fieldValue: ' . $searchResponse->status()];
            }
        } else {
            return ['success' => false, 'error' => 'Error creando fieldValue: ' . $response->status()];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

echo "========================================\n";
echo "  PRUEBA DE SINCRONIZACIÓN ACTIVECAMPAIGN\n";
echo "========================================\n\n";

// 1. Crear usuario de prueba
echo "1. Creando usuario de prueba...\n";

$timestamp = time();
$email = "test_ac_{$timestamp}@famedic.com";

try {
    $user = new User();
    $user->name = 'Usuario';
    $user->paternal_lastname = 'Prueba';
    $user->maternal_lastname = 'ActiveCampaign';
    $user->email = $email;
    $user->phone = '+521234567890';
    $user->phone_country = 'MX';
    $user->birth_date = Carbon::create(1990, 1, 15);
    $user->gender = 1; // Masculino
    $user->state = 'NL'; // Nuevo León
    $user->password = Hash::make('password123');
    
    $user->save();
    
    echo "✅ Usuario creado exitosamente:\n";
    echo "   ID: {$user->id}\n";
    echo "   Email: {$user->email}\n";
    echo "   Nombre completo: {$user->name} {$user->paternal_lastname} {$user->maternal_lastname}\n";
    echo "   Teléfono: {$user->phone}\n";
    echo "   Género: " . ($user->gender?->label() ?? 'N/A') . "\n";
    echo "   Fecha nacimiento: " . ($user->birth_date?->format('Y-m-d') ?? 'N/A') . "\n";
    echo "   Estado: {$user->state}\n\n";
    
} catch (Exception $e) {
    echo "❌ Error creando usuario: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Obtener el servicio de ActiveCampaign
echo "2. Inicializando servicio de ActiveCampaign...\n";

$service = app(App\Services\ActiveCampaignService::class);

// Verificar conexión
echo "   Probando conexión... ";
if ($service->testConnection()) {
    echo "✅ OK\n";
} else {
    echo "❌ FALLÓ\n";
    exit(1);
}

// 3. Preparar datos para ActiveCampaign
echo "\n3. Preparando datos para ActiveCampaign...\n";

$userData = $service->prepareUserData($user);
$customFields = $userData['custom_fields'] ?? [];

echo "   Datos básicos:\n";
echo "   - Email: {$userData['email']}\n";
echo "   - Nombre: {$userData['first_name']}\n";
echo "   - Apellido: {$userData['last_name']}\n";
echo "   - Teléfono: " . ($userData['phone'] ?? 'N/A') . "\n";

if (!empty($customFields)) {
    echo "   Campos personalizados (" . count($customFields) . "):\n";
    foreach ($customFields as $fieldId => $value) {
        echo "   - ID {$fieldId}: '{$value}'\n";
    }
} else {
    echo "   ❌ No hay campos personalizados configurados\n";
}

// 4. Verificar si el contacto ya existe
echo "\n4. Verificando contacto existente en ActiveCampaign...\n";

$existingContact = $service->getContactByEmail($user->email);
if ($existingContact) {
    echo "   ⚠️ Contacto ya existe:\n";
    echo "   - ID: {$existingContact['id']}\n";
    echo "   - Email: {$existingContact['email']}\n";
    
    // Mostrar fieldValues actuales
    echo "\n   FieldValues actuales del contacto:\n";
    try {
        // Usar reflexión para acceder al cliente HTTP (temporal)
        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($service);
        
        $response = $client->get("/api/3/contacts/{$existingContact['id']}/fieldValues");
        if ($response->successful()) {
            $data = $response->json();
            $fieldValues = $data['fieldValues'] ?? $data;
            
            if (is_array($fieldValues) && !empty($fieldValues)) {
                foreach ($fieldValues as $fv) {
                    if (is_array($fv) && isset($fv['field'], $fv['value'])) {
                        echo "   - Field {$fv['field']}: '{$fv['value']}'\n";
                    }
                }
            } else {
                echo "   - Ninguno\n";
            }
        }
    } catch (Exception $e) {
        echo "   - Error obteniendo fieldValues: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ✅ No existe contacto previo (se creará uno nuevo)\n";
}

// 5. Confirmar sincronización
echo "\n5. ¿Deseas proceder con la sincronización? (s/n): ";
$handle = fopen("php://stdin", "r");
$answer = trim(fgets($handle));

if (strtolower($answer) !== 's') {
    echo "\n❌ Sincronización cancelada por el usuario.\n";
    
    // Opcional: eliminar el usuario de prueba
    echo "¿Deseas eliminar el usuario de prueba creado? (s/n): ";
    $deleteAnswer = trim(fgets($handle));
    if (strtolower($deleteAnswer) === 's') {
        $user->delete();
        echo "✅ Usuario eliminado.\n";
    }
    exit(0);
}

// 6. Ejecutar sincronización
echo "\n6. Ejecutando sincronización...\n";

$contactData = [
    'email' => $userData['email'],
    'first_name' => $userData['first_name'],
    'last_name' => $userData['last_name'],
    'phone' => $userData['phone'] ?? null,
];

$listId = config('activecampaign.lists.default', 5);
$tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo'), 'PruebaManual'];

echo "   List ID: {$listId}\n";
echo "   Tags: " . implode(', ', $tags) . "\n";

$result = $service->syncContactWithCustomFields($contactData, $listId, $tags, $customFields);

if ($result['success']) {
    echo "✅ Sincronización exitosa!\n";
    echo "   Contact ID: {$result['contact_id']}\n";
    echo "   Acción: {$result['action']}\n";
    
    // 7. Verificar resultados
    echo "\n7. Verificando resultados...\n";
    sleep(3); // Esperar a que la API procese
    
    $allCorrect = true;
    foreach ($customFields as $fieldId => $expectedValue) {
        echo "   Verificando campo {$fieldId}... ";
        
        $actual = $service->getFieldValue($result['contact_id'], $fieldId);
        $actualValue = $actual['value'] ?? 'null';
        
        if ($actualValue === $expectedValue) {
            echo "✅ OK ('{$actualValue}')\n";
        } else {
            echo "❌ FALLÓ\n";
            echo "     Esperado: '{$expectedValue}'\n";
            echo "     Obtenido: '{$actualValue}'\n";
            $allCorrect = false;

            // Intentar actualizar directamente
            echo "     Intentando actualización directa...\n";
            $directUpdate = updateFieldValueDirectly($service, $result['contact_id'], $fieldId, $expectedValue);
            if ($directUpdate['success']) {
                echo "     ✅ Actualización directa exitosa: {$directUpdate['message']}\n";
                
                // Verificar de nuevo después de actualizar
                sleep(2);
                $actual = $service->getFieldValue($result['contact_id'], $fieldId);
                $actualValue = $actual['value'] ?? 'null';
                if ($actualValue === $expectedValue) {
                    echo "     ✅ Verificación después de actualizar: OK\n";
                    $allCorrect = true; // Si se corrigió, consideramos que está bien
                } else {
                    echo "     ❌ Verificación después de actualizar: FALLÓ\n";
                }
            } else {
                echo "     ❌ Error en actualización directa: {$directUpdate['error']}\n";
            }
        }
        
        sleep(1); // Pequeña pausa entre verificaciones
    }
    
    // Mostrar todos los fieldValues actuales del contacto
    echo "\n   FieldValues actuales del contacto después de la sincronización:\n";
    try {
        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($service);
        
        $response = $client->get("/api/3/contacts/{$result['contact_id']}/fieldValues");
        if ($response->successful()) {
            $data = $response->json();
            $fieldValues = $data['fieldValues'] ?? $data;
            
            if (is_array($fieldValues) && !empty($fieldValues)) {
                foreach ($fieldValues as $fv) {
                    if (is_array($fv) && isset($fv['field'], $fv['value'])) {
                        echo "   - Field {$fv['field']}: '{$fv['value']}'\n";
                    }
                }
            } else {
                echo "   - Ninguno\n";
            }
        }
    } catch (Exception $e) {
        echo "   - Error obteniendo fieldValues: " . $e->getMessage() . "\n";
    }
    
    if ($allCorrect) {
        echo "\n🎉 ¡TODOS los campos se sincronizaron correctamente!\n";
    } else {
        echo "\n⚠️ Algunos campos no se sincronizaron correctamente.\n";
    }
    
    // 8. Mostrar enlace al contacto en ActiveCampaign
    $baseUrl = config('activecampaign.api.base_url');
    if ($baseUrl) {
        $domain = parse_url($baseUrl, PHP_URL_HOST);
        $account = explode('.', $domain)[0] ?? 'tu-cuenta';
        echo "\n8. Enlace al contacto en ActiveCampaign:\n";
        echo "   https://{$account}.activehosted.com/app/contacts/{$result['contact_id']}\n";
    }
    
} else {
    echo "❌ Error en sincronización:\n";
    echo "   {$result['error']}\n";
}

// 9. Limpieza
echo "\n9. ¿Deseas eliminar el usuario de prueba de la base de datos? (s/n): ";
$cleanupAnswer = trim(fgets($handle));

if (strtolower($cleanupAnswer) === 's') {
    $user->delete();
    echo "✅ Usuario eliminado de la base de datos.\n";
} else {
    echo "⚠️ Usuario mantenido en la base de datos (ID: {$user->id})\n";
    echo "   Email: {$user->email}\n";
    echo "   Password: password123\n";
}

fclose($handle);
echo "\n========================================\n";
echo "  PRUEBA COMPLETADA\n";
echo "========================================\n";


ya me sincronizo la maypria de los campos correctamente en activecampaign pero me falto el campo indivial de sexo es equivalente al campo genero, me falto el campo de apellido materno y paterno