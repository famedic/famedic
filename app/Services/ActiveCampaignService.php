<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ActiveCampaignService
{
    protected PendingRequest $client;
    protected string $baseUrl;
    protected string $apiToken;

    public function __construct()
    {
        $this->baseUrl = config('activecampaign.api.base_url');
        $this->apiToken = config('activecampaign.api.token');

        $this->client = Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->retry(3, 100)
            ->withHeaders([
                'Api-Token' => $this->apiToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Sync contact with custom fields
     */
    public function syncContactWithCustomFields(array $userData, int $listId, array $tags = [], array $customFields = []): array
    {
        \Log::info('AC Service: Sincronización SEGURA con campos personalizados', [
            'email' => $userData['email'],
            'has_custom_fields' => !empty($customFields),
            'custom_fields_count' => count($customFields),
        ]);

        try {
            // 1. Check if contact exists
            $existingContact = $this->getContactByEmail($userData['email']);

            if ($existingContact) {
                $contact = $this->updateContact($existingContact['id'], $userData);
                $contactId = $existingContact['id'];
                $action = 'updated';
            } else {
                $contact = $this->createContact($userData);
                $contactId = $contact['contact']['id'];
                $action = 'created';
            }

            \Log::info('AC Service: Contacto procesado', [
                'contact_id' => $contactId,
                'action' => $action,
            ]);

            // Esperar un momento antes de operaciones adicionales
            sleep(1);

            // 2. Add to list
            $this->addContactToList($contactId, $listId);

            // 3. Add tags
            foreach ($tags as $tag) {
                $this->addTagToContact($contactId, $tag);
            }

            // 4. Update custom fields con método SEGURO
            if (!empty($customFields)) {
                \Log::info('AC Service: Actualizando campos personalizados SEGURO', [
                    'contact_id' => $contactId,
                    'fields_count' => count($customFields),
                    'field_ids' => array_keys($customFields),
                ]);

                // Esperar un poco más si el contacto es nuevo
                if ($action === 'created') {
                    sleep(2);
                }

                $fieldsUpdated = $this->syncCustomFieldsSafely($contactId, $customFields);

                if (!$fieldsUpdated) {
                    \Log::warning('AC Service: Algunos campos personalizados fallaron', [
                        'contact_id' => $contactId,
                    ]);
                }
            }

            return [
                'success' => true,
                'contact_id' => $contactId,
                'action' => $action,
                'custom_fields_updated' => !empty($customFields),
            ];

        } catch (\Exception $e) {
            \Log::error('ActiveCampaign sync failed', [
                'user_data' => $this->maskEmail($userData),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create contact
     */
    public function createContact(array $contactData): array
    {
        $response = $this->client->post('/api/3/contacts', [
            'contact' => [
                'email' => $contactData['email'],
                'firstName' => $contactData['first_name'] ?? null,
                'lastName' => $contactData['last_name'] ?? null,
                'phone' => $contactData['phone'] ?? null,
            ]
        ]);

        $this->validateResponse($response, 'createContact');
        return $response->json();
    }

    /**
     * Update contact
     */
    public function updateContact(int $contactId, array $contactData): array
    {
        $response = $this->client->put("/api/3/contacts/{$contactId}", [
            'contact' => [
                'email' => $contactData['email'],
                'firstName' => $contactData['first_name'] ?? null,
                'lastName' => $contactData['last_name'] ?? null,
                'phone' => $contactData['phone'] ?? null,
            ]
        ]);

        $this->validateResponse($response, 'updateContact');
        return $response->json();
    }

    /**
     * Get contact by email
     */
    public function getContactByEmail(string $email): ?array
    {
        $response = $this->client->get('/api/3/contacts', [
            'email' => $email
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return $data['contacts'][0] ?? null;
        }

        return null;
    }

    /**
     * Add contact to list
     */
    public function addContactToList(int $contactId, int $listId): array
    {
        $response = $this->client->post('/api/3/contactLists', [
            'contactList' => [
                'list' => $listId,
                'contact' => $contactId,
                'status' => 1 // Subscribed
            ]
        ]);

        $this->validateResponse($response, 'addContactToList');
        return $response->json();
    }

    /**
     * Get or create tag by name
     */
    public function getOrCreateTag(string $tagName): ?int
    {
        // First, try to find existing tag
        $response = $this->client->get('/api/3/tags', [
            'search' => $tagName
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (!empty($data['tags'])) {
                return $data['tags'][0]['id'];
            }
        }

        // Create new tag
        $response = $this->client->post('/api/3/tags', [
            'tag' => [
                'tag' => $tagName,
                'tagType' => 'contact',
                'description' => 'Automatically created by Laravel sync'
            ]
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return $data['tag']['id'];
        }

        return null;
    }

    /**
     * Add tag to contact
     */
    public function addTagToContact(int $contactId, string $tagName): bool
    {
        $tagId = $this->getOrCreateTag($tagName);

        if (!$tagId) {
            return false;
        }

        $response = $this->client->post('/api/3/contactTags', [
            'contactTag' => [
                'contact' => $contactId,
                'tag' => $tagId
            ]
        ]);

        return $response->successful();
    }

    /**
     * Update multiple custom fields (REESCRITO - SEGURO)
     */
    public function updateCustomFields(int $contactId, array $fieldValues): bool
    {
        \Log::info('ActiveCampaignService: updateCustomFields REESCRITO', [
            'contact_id' => $contactId,
            'fieldValues' => $fieldValues,
        ]);

        if (empty($fieldValues)) {
            \Log::info('ActiveCampaignService: No hay fieldValues para actualizar');
            return true;
        }

        $allSuccessful = true;

        foreach ($fieldValues as $fieldId => $value) {
            if ($value === null || $value === '') {
                \Log::debug('ActiveCampaignService: Saltando campo vacío', [
                    'field_id' => $fieldId,
                ]);
                continue;
            }

            \Log::info('ActiveCampaignService: Procesando campo', [
                'contact_id' => $contactId,
                'field_id' => $fieldId,
                'value' => $value,
            ]);

            $success = $this->updateCustomFieldSafely($contactId, $fieldId, $value);

            if (!$success) {
                $allSuccessful = false;
                \Log::error('ActiveCampaignService: Falló campo', [
                    'contact_id' => $contactId,
                    'field_id' => $fieldId,
                ]);
            }

            // Pequeña pausa para evitar rate limiting
            usleep(300000); // 0.3 segundos
        }

        \Log::info('ActiveCampaignService: Resultado final updateCustomFields', [
            'contact_id' => $contactId,
            'total_campos' => count($fieldValues),
            'exitosos' => $allSuccessful ? 'todos' : 'algunos fallaron',
        ]);

        return $allSuccessful;
    }

    /**
     * Update single custom field with proper duplicate handling
     */
    public function updateCustomField(int $contactId, int $fieldId, $value): bool
    {
        \Log::debug('AC Service: updateCustomField START', [
            'contact_id' => $contactId,
            'field_id' => $fieldId,
            'value' => $value,
        ]);

        // Primero, buscar si ya existe un valor para este campo
        $existingFieldValue = $this->getFieldValue($contactId, $fieldId);

        \Log::debug('AC Service: Existing field value check', [
            'exists' => !empty($existingFieldValue),
            'existing_value' => $existingFieldValue['value'] ?? null,
            'field_value_id' => $existingFieldValue['id'] ?? null,
        ]);

        if ($existingFieldValue) {
            \Log::debug('AC Service: Field exists, updating', [
                'field_value_id' => $existingFieldValue['id'],
                'current_value' => $existingFieldValue['value'],
                'new_value' => $value,
            ]);

            // Si existe, actualizarlo
            $fieldValueId = $existingFieldValue['id'];

            $response = $this->client->put("/api/3/fieldValues/{$fieldValueId}", [
                'fieldValue' => [
                    'contact' => $contactId,
                    'field' => $fieldId,
                    'value' => (string) $value
                ]
            ]);

            \Log::debug('AC Service: PUT response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->successful() ? '...' : $response->body(),
            ]);

            if (!$response->successful() && $response->status() === 404) {
                \Log::debug('AC Service: Field value not found (404), creating new');
                // Si el fieldValue no existe (aunque getFieldValue dijo que sí), crear uno nuevo
                return $this->createFieldValue($contactId, $fieldId, $value);
            }

            $success = $response->successful();
            \Log::debug('AC Service: Update result', ['success' => $success]);

            return $success;
        } else {
            \Log::debug('AC Service: Field does not exist, creating new');
            // Si no existe, crear uno nuevo
            return $this->createFieldValue($contactId, $fieldId, $value);
        }
    }

    /**
     * Create new field value
     */
    private function createFieldValue(int $contactId, int $fieldId, $value): bool
    {
        $response = $this->client->post('/api/3/fieldValues', [
            'fieldValue' => [
                'contact' => $contactId,
                'field' => $fieldId,
                'value' => (string) $value
            ]
        ]);

        return $response->successful();
    }

    /**
     * Get existing field value for a contact (VERSIÓN COMPLETAMENTE NUEVA)
     */
    public function getFieldValue(int $contactId, int $fieldId): ?array
    {
        try {
            \Log::debug('ActiveCampaignService: getFieldValue - Iniciando búsqueda', [
                'contact_id' => $contactId,
                'field_id' => $fieldId,
            ]);

            // Opción 1: Usar el endpoint específico de fieldValues con filtros
            $response = $this->client->get("/api/3/fieldValues", [
                'filters[contactid]' => $contactId,
                'filters[fieldid]' => $fieldId,
                'limit' => 1
            ]);

            \Log::debug('ActiveCampaignService: getFieldValue - Respuesta de API', [
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);

            if ($response->successful()) {
                $data = $response->json();

                \Log::debug('ActiveCampaignService: getFieldValue - Datos de respuesta', [
                    'has_fieldValues' => isset($data['fieldValues']),
                    'count' => isset($data['fieldValues']) ? count($data['fieldValues']) : 0,
                ]);

                if (!empty($data['fieldValues'])) {
                    $fieldValue = $data['fieldValues'][0];

                    // Verificar que la estructura sea correcta
                    if (is_array($fieldValue) && isset($fieldValue['id'])) {
                        \Log::debug('ActiveCampaignService: FieldValue encontrado', [
                            'id' => $fieldValue['id'],
                            'contact' => $fieldValue['contact'] ?? 'n/a',
                            'field' => $fieldValue['field'] ?? 'n/a',
                            'value' => $fieldValue['value'] ?? 'n/a',
                        ]);

                        // Verificar adicionalmente que pertenece al contacto correcto
                        if (isset($fieldValue['contact']) && (int) $fieldValue['contact'] === $contactId) {
                            return $fieldValue;
                        } else {
                            \Log::warning('ActiveCampaignService: FieldValue encontrado pero con contacto diferente', [
                                'expected' => $contactId,
                                'actual' => $fieldValue['contact'] ?? 'n/a',
                            ]);
                        }
                    } else {
                        \Log::warning('ActiveCampaignService: FieldValue encontrado pero estructura incorrecta', [
                            'fieldValue_type' => gettype($fieldValue),
                        ]);
                    }
                }
            } else {
                \Log::warning('ActiveCampaignService: API no respondió exitosamente', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            // Opción 2: Si la primera opción falla, usar el endpoint del contacto
            \Log::debug('ActiveCampaignService: Intentando método alternativo...');

            $response = $this->client->get("/api/3/contacts/{$contactId}/fieldValues");

            if ($response->successful()) {
                $data = $response->json();
                $fieldValues = $data['fieldValues'] ?? [];

                foreach ($fieldValues as $fv) {
                    if (is_array($fv) && isset($fv['field']) && (int) $fv['field'] === $fieldId) {
                        \Log::debug('ActiveCampaignService: FieldValue encontrado en método alternativo', [
                            'id' => $fv['id'] ?? 'n/a',
                            'value' => $fv['value'] ?? 'n/a',
                        ]);
                        return $fv;
                    }
                }
            }

            \Log::debug('ActiveCampaignService: No se encontró fieldValue');
            return null;

        } catch (\Exception $e) {
            \Log::error('ActiveCampaignService: Error en getFieldValue', [
                'contact_id' => $contactId,
                'field_id' => $fieldId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Prepare user data with custom fields
     */
    public function prepareUserData(\App\Models\User $user): array
    {
        $userData = [
            'email' => $user->email,
            'first_name' => $user->name,
            'last_name' => trim(($user->paternal_lastname ?? '') . ' ' . ($user->maternal_lastname ?? '')),
            'phone' => $user->phone,
        ];

        // Preparar campos personalizados
        $customFields = $this->prepareCustomFields($user);

        if (!empty($customFields)) {
            $userData['custom_fields'] = $customFields;
        }

        return $userData;
    }

    /**
     * Prepare custom fields from user model
     */
    public function prepareCustomFields(\App\Models\User $user): array
    {
        $fieldMapping = config('activecampaign.field_mapping', []);

        \Log::info('ActiveCampaignService: prepareCustomFields - Mapeo actual', [
            'user_id' => $user->id,
            'field_mapping' => $fieldMapping,
        ]);

        $customFields = [];

        foreach ($fieldMapping as $field => $fieldId) {
            if (!$fieldId) {
                \Log::debug('ActiveCampaignService: Campo sin ID, saltando', ['field' => $field]);
                continue;
            }

            $value = null;

            // Solo procesar campos que realmente necesitamos
            switch ($field) {
                case 'gender':
                    if ($user->gender) {
                        $genderValue = $user->gender->value ?? $user->gender;
                        $value = $this->mapGenderToSpanish($genderValue);
                        \Log::info("ActiveCampaignService: gender field procesado", [
                            'field' => $field,
                            'fieldId' => $fieldId,
                            'value' => $value,
                        ]);
                    }
                    break;

                case 'birth_date':
                    if ($user->birth_date) {
                        $value = $user->birth_date->format('Y-m-d');
                        \Log::info("ActiveCampaignService: birth_date field procesado", [
                            'field' => $field,
                            'fieldId' => $fieldId,
                            'value' => $value,
                        ]);
                    }
                    break;

                case 'state':
                    if ($user->state) {
                        $value = $this->mapStateCodeToName($user->state);
                        \Log::info("ActiveCampaignService: state field procesado", [
                            'field' => $field,
                            'fieldId' => $fieldId,
                            'state' => $user->state,
                            'value' => $value,
                        ]);
                    }
                    break;

                case 'phone_country':
                    $value = $user->phone_country ?? null;
                    if ($value) {
                        \Log::info("ActiveCampaignService: phone_country field procesado", [
                            'field' => $field,
                            'fieldId' => $fieldId,
                            'value' => $value,
                        ]);
                    }
                    break;

                case 'created_at':
                    if ($user->created_at) {
                        $value = $user->created_at->format('Y-m-d');
                        \Log::info("ActiveCampaignService: created_at field procesado", [
                            'field' => $field,
                            'fieldId' => $fieldId,
                            'value' => $value,
                        ]);
                    }
                    break;

                case 'referred_by':
                    if ($user->referred_by) {
                        $referrer = $user->referrer;
                        $value = $referrer ? $referrer->full_name : "ID: {$user->referred_by}";
                        \Log::info("ActiveCampaignService: referred_by field procesado", [
                            'field' => $field,
                            'fieldId' => $fieldId,
                            'value' => $value,
                        ]);
                    }
                    break;

                default:
                    \Log::warning('ActiveCampaignService: Campo no reconocido en mapping', [
                        'field' => $field,
                        'fieldId' => $fieldId,
                    ]);
                    break;
            }

            if ($value !== null && $value !== '') {
                $customFields[$fieldId] = (string) $value;
            }
        }

        \Log::info('ActiveCampaignService: Campos personalizados finales', [
            'user_id' => $user->id,
            'custom_fields_count' => count($customFields),
            'custom_fields_ids' => array_keys($customFields),
        ]);

        return $customFields;
    }

    /**
     * Map numeric gender to Spanish text
     */
    public function mapGenderToSpanish($genderValue): string
    {
        return match ((string) $genderValue) {
            '1' => 'Masculino',
            '2' => 'Femenino',
            default => (string) $genderValue,
        };
    }

    /**
     * Map state code to state name
     */
    public function mapStateCodeToName(string $stateCode): string
    {
        $states = [
            'NL' => 'Nuevo León',
            'DF' => 'Ciudad de México',
            'AG' => 'Aguascalientes',
            'BC' => 'Baja California',
            'BS' => 'Baja California Sur',
            'CM' => 'Campeche',
            'CS' => 'Chiapas',
            'CH' => 'Chihuahua',
            'CO' => 'Coahuila',
            'CL' => 'Colima',
            'DG' => 'Durango',
            'GT' => 'Guanajuato',
            'GR' => 'Guerrero',
            'HG' => 'Hidalgo',
            'JC' => 'Jalisco',
            'MX' => 'Estado de México',
            'MI' => 'Michoacán',
            'MO' => 'Morelos',
            'NA' => 'Nayarit',
            'OA' => 'Oaxaca',
            'PU' => 'Puebla',
            'QT' => 'Querétaro',
            'QR' => 'Quintana Roo',
            'SL' => 'San Luis Potosí',
            'SI' => 'Sinaloa',
            'SO' => 'Sonora',
            'TB' => 'Tabasco',
            'TM' => 'Tamaulipas',
            'TL' => 'Tlaxcala',
            'VE' => 'Veracruz',
            'YU' => 'Yucatán',
            'ZA' => 'Zacatecas',
        ];

        return $states[strtoupper($stateCode)] ?? $stateCode;
    }

    /**
     * Sync contact (legacy method for backward compatibility)
     */
    public function syncContact(array $userData, int $listId, array $tags = []): array
    {
        return $this->syncContactWithCustomFields($userData, $listId, $tags);
    }

    /**
     * Response validation
     */
    protected function validateResponse($response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $errorData = $response->json();

        $errorMap = [
            400 => 'Bad request to ActiveCampaign',
            401 => 'Invalid API token',
            403 => 'Insufficient permissions or feature not available in your plan',
            404 => 'Resource not found',
            422 => 'Validation error: ' . ($errorData['message'] ?? ''),
            429 => 'Rate limit exceeded',
            500 => 'ActiveCampaign server error',
        ];

        $message = $errorMap[$status] ?? "Error {$status} in {$operation}";

        // Agregar detalles específicos si están disponibles
        if (isset($errorData['errors'][0]['detail'])) {
            $message .= ' - ' . $errorData['errors'][0]['detail'];
        }

        throw new \Exception($message);
    }

    /**
     * Mask email for logging
     */
    private function maskEmail(array $data): array
    {
        if (isset($data['email'])) {
            $data['email'] = substr($data['email'], 0, 3) . '***@***' . substr(strrchr($data['email'], "@"), 1);
        }
        return $data;
    }

    /**
     * Get all custom fields from ActiveCampaign
     */
    public function getAllCustomFields(): array
    {
        $response = $this->client->get('/api/3/fields');

        if ($response->successful()) {
            $data = $response->json();
            return $data['fields'] ?? [];
        }

        return [];
    }

    /**
     * Test API connection using a simple endpoint
     */
    public function testConnection(): bool
    {
        try {
            // Usar un endpoint más simple que esté disponible en todos los planes
            $response = $this->client->get('/api/3/lists?limit=1');

            return $response->successful();
        } catch (\Exception $e) {
            \Log::warning('ActiveCampaign connection test failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get contact details by ID
     */
    public function getContactById(int $contactId): ?array
    {
        $response = $this->client->get("/api/3/contacts/{$contactId}");

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    // Agrega este método a tu ActiveCampaignService
    public function debugUpdateProcess(int $contactId, int $fieldId, $value): array
    {
        \Log::info('🔍 DEBUG: Starting debugUpdateProcess', [
            'contact_id' => $contactId,
            'field_id' => $fieldId,
            'value' => $value,
        ]);

        $debugSteps = [];

        // Paso 1: Intentar obtener valor existente
        try {
            $response = $this->client->get('/api/3/fieldValues', [
                'filters[contactid]' => $contactId,
                'filters[fieldid]' => $fieldId,
                'limit' => 1,
            ]);

            $debugSteps['get_field_value'] = [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->successful() ? $response->json() : $response->body(),
            ];

            if ($response->successful()) {
                $data = $response->json();
                $existing = $data['fieldValues'][0] ?? null;

                if ($existing) {
                    \Log::info('🔍 DEBUG: Field value exists', [
                        'field_value_id' => $existing['id'],
                        'current_value' => $existing['value'],
                    ]);

                    // Paso 2: Intentar actualizar
                    $updateResponse = $this->client->put("/api/3/fieldValues/{$existing['id']}", [
                        'fieldValue' => [
                            'contact' => $contactId,
                            'field' => $fieldId,
                            'value' => (string) $value
                        ]
                    ]);

                    $debugSteps['update_existing'] = [
                        'status' => $updateResponse->status(),
                        'successful' => $updateResponse->successful(),
                        'body' => $updateResponse->successful() ? 'Updated' : $updateResponse->body(),
                    ];

                    if ($updateResponse->successful()) {
                        return ['success' => true, 'action' => 'updated', 'debug' => $debugSteps];
                    }
                } else {
                    \Log::info('🔍 DEBUG: Field value does not exist, creating new');
                }
            }
        } catch (\Exception $e) {
            $debugSteps['get_error'] = $e->getMessage();
        }

        // Paso 3: Si no existe o falla la actualización, crear nuevo
        try {
            $createResponse = $this->client->post('/api/3/fieldValues', [
                'fieldValue' => [
                    'contact' => $contactId,
                    'field' => $fieldId,
                    'value' => (string) $value
                ]
            ]);

            $debugSteps['create_new'] = [
                'status' => $createResponse->status(),
                'successful' => $createResponse->successful(),
                'body' => $createResponse->successful() ? 'Created' : $createResponse->body(),
            ];

            if ($createResponse->successful()) {
                return ['success' => true, 'action' => 'created', 'debug' => $debugSteps];
            } else {
                return ['success' => false, 'error' => 'Failed to create', 'debug' => $debugSteps];
            }
        } catch (\Exception $e) {
            $debugSteps['create_error'] = $e->getMessage();
            return ['success' => false, 'error' => $e->getMessage(), 'debug' => $debugSteps];
        }
    }

    /**
     * Actualizar campos personalizados con reintentos
     */
    public function updateCustomFieldsWithRetry(int $contactId, array $fieldValues, int $maxRetries = 3): bool
    {
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            Log::info('ActiveCampaignService: Intento de actualización de campos', [
                'contact_id' => $contactId,
                'intento' => $retryCount + 1,
                'max_retries' => $maxRetries,
            ]);

            $success = $this->updateCustomFields($contactId, $fieldValues);

            if ($success) {
                Log::info('ActiveCampaignService: Campos actualizados exitosamente', [
                    'contact_id' => $contactId,
                    'intentos' => $retryCount + 1,
                ]);
                return true;
            }

            $retryCount++;

            if ($retryCount < $maxRetries) {
                // Esperar antes de reintentar (exponencial backoff)
                $waitTime = pow(2, $retryCount) * 1000000; // 1, 2, 4 segundos
                Log::warning('ActiveCampaignService: Reintentando actualización de campos', [
                    'contact_id' => $contactId,
                    'intento' => $retryCount + 1,
                    'wait_microseconds' => $waitTime,
                ]);
                usleep($waitTime);
            }
        }

        Log::error('ActiveCampaignService: Falló después de múltiples intentos', [
            'contact_id' => $contactId,
            'intentos' => $maxRetries,
        ]);

        return false;
    }

    /**
     * Update single custom field with guaranteed isolation
     */
    public function updateCustomFieldSafely(int $contactId, int $fieldId, $value): bool
    {
        \Log::info('ActiveCampaignService: updateCustomFieldSafely START', [
            'contact_id' => $contactId,
            'field_id' => $fieldId,
            'value' => $value,
        ]);

        try {
            // PRIMERO: Obtener todos los fieldValues del contacto
            $contactResponse = $this->client->get("/api/3/contacts/{$contactId}", [
                'include' => 'fieldValues'
            ]);

            if (!$contactResponse->successful()) {
                \Log::error('ActiveCampaignService: No se pudo obtener el contacto', [
                    'contact_id' => $contactId,
                    'status' => $contactResponse->status(),
                ]);
                return false;
            }

            $contactData = $contactResponse->json();
            $fieldValues = $contactData['contact']['fieldValues'] ?? [];

            \Log::debug('ActiveCampaignService: FieldValues del contacto', [
                'contact_id' => $contactId,
                'total_fieldValues' => count($fieldValues),
            ]);

            // Buscar si ya existe este field para este contacto
            $existingFieldValueId = null;
            foreach ($fieldValues as $fieldValue) {
                if ((int) $fieldValue['field'] === $fieldId) {
                    $existingFieldValueId = $fieldValue['id'];
                    \Log::debug('ActiveCampaignService: FieldValue existente encontrado', [
                        'fieldValue_id' => $existingFieldValueId,
                        'current_value' => $fieldValue['value'],
                    ]);
                    break;
                }
            }

            if ($existingFieldValueId) {
                // ACTUALIZAR el fieldValue existente
                $response = $this->client->put("/api/3/fieldValues/{$existingFieldValueId}", [
                    'fieldValue' => [
                        'contact' => $contactId,
                        'field' => $fieldId,
                        'value' => (string) $value
                    ]
                ]);

                \Log::debug('ActiveCampaignService: PUT response', [
                    'status' => $response->status(),
                    'successful' => $response->successful(),
                ]);

                if ($response->successful()) {
                    \Log::info('ActiveCampaignService: FieldValue actualizado exitosamente');
                    return true;
                }

                \Log::warning('ActiveCampaignService: Falló PUT, intentando POST', [
                    'status' => $response->status(),
                ]);
            }

            // CREAR nuevo fieldValue (si no existía o falló la actualización)
            $response = $this->client->post('/api/3/fieldValues', [
                'fieldValue' => [
                    'contact' => $contactId,
                    'field' => $fieldId,
                    'value' => (string) $value
                ]
            ]);

            \Log::debug('ActiveCampaignService: POST response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);

            return $response->successful();

        } catch (\Exception $e) {
            \Log::error('ActiveCampaignService: Excepción en updateCustomFieldSafely', [
                'contact_id' => $contactId,
                'field_id' => $fieldId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Método alternativo usando el endpoint correcto
     */
    public function getFieldValueFixed(int $contactId, int $fieldId): ?array
    {
        // Método 1: Usar el endpoint de fieldValues con parámetros en la URL
        $url = "/api/3/fieldValues?filters[contactid]={$contactId}&filters[fieldid]={$fieldId}";

        $response = $this->client->get($url);

        if ($response->successful()) {
            $data = $response->json();

            // La API debería devolver solo los fieldValues de este contacto y campo
            // pero verificamos por si acaso
            foreach ($data['fieldValues'] ?? [] as $fieldValue) {
                if ((int) $fieldValue['contact'] === $contactId && (int) $fieldValue['field'] === $fieldId) {
                    return $fieldValue;
                }
            }
        }

        return null;
    }

    /**
     * Actualizar campos personalizados de forma SEGURA (sin contaminación cruzada)
     * VERSIÓN CORREGIDA
     */
    public function syncCustomFieldsSafely(int $contactId, array $fieldValues): bool
    {
        if (empty($fieldValues)) {
            return true;
        }

        \Log::info('ActiveCampaignService: syncCustomFieldsSafely INICIO', [
            'contact_id' => $contactId,
            'field_count' => count($fieldValues),
        ]);

        $allSuccess = true;

        foreach ($fieldValues as $fieldId => $value) {
            try {
                \Log::info('ActiveCampaignService: Procesando campo', [
                    'contact_id' => $contactId,
                    'field_id' => $fieldId,
                    'value' => $value,
                ]);

                // Buscar fieldValue existente
                $existingFieldValue = $this->getFieldValue($contactId, $fieldId);

                if ($existingFieldValue && isset($existingFieldValue['id'])) {
                    // Actualizar fieldValue existente
                    $response = $this->client->put("/api/3/fieldValues/{$existingFieldValue['id']}", [
                        'fieldValue' => [
                            'contact' => $contactId,
                            'field' => $fieldId,
                            'value' => (string) $value
                        ]
                    ]);

                    if ($response->successful()) {
                        \Log::info('ActiveCampaignService: Campo actualizado exitosamente', [
                            'contact_id' => $contactId,
                            'field_id' => $fieldId,
                            'fieldValue_id' => $existingFieldValue['id'],
                        ]);
                    } else {
                        \Log::error('ActiveCampaignService: Error actualizando campo', [
                            'contact_id' => $contactId,
                            'field_id' => $fieldId,
                            'status' => $response->status(),
                        ]);
                        $allSuccess = false;
                    }
                } else {
                    // Crear nuevo fieldValue
                    $response = $this->client->post('/api/3/fieldValues', [
                        'fieldValue' => [
                            'contact' => $contactId,
                            'field' => $fieldId,
                            'value' => (string) $value
                        ]
                    ]);

                    if ($response->successful()) {
                        \Log::info('ActiveCampaignService: Campo creado exitosamente', [
                            'contact_id' => $contactId,
                            'field_id' => $fieldId,
                        ]);
                    } else {
                        \Log::error('ActiveCampaignService: Error creando campo', [
                            'contact_id' => $contactId,
                            'field_id' => $fieldId,
                            'status' => $response->status(),
                        ]);
                        $allSuccess = false;
                    }
                }

                usleep(300000); // 0.3 segundos

            } catch (\Exception $e) {
                \Log::error('ActiveCampaignService: Excepción procesando campo', [
                    'contact_id' => $contactId,
                    'field_id' => $fieldId,
                    'error' => $e->getMessage(),
                ]);
                $allSuccess = false;
            }
        }

        \Log::info('ActiveCampaignService: syncCustomFieldsSafely FIN', [
            'contact_id' => $contactId,
            'success' => $allSuccess,
        ]);

        return $allSuccess;
    }

    /**
     * Encontrar fieldValue de forma segura (método auxiliar)
     */
    private function findFieldValueSafely(int $contactId, int $fieldId): ?array
    {
        try {
            // Obtener todos los fieldValues del contacto
            $response = $this->client->get("/api/3/contacts/{$contactId}/fieldValues");

            if (!$response->successful()) {
                \Log::error('ActiveCampaignService: Error obteniendo fieldValues del contacto', [
                    'contact_id' => $contactId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();
            $fieldValues = $data['fieldValues'] ?? [];

            \Log::debug('ActiveCampaignService: FieldValues del contacto', [
                'contact_id' => $contactId,
                'total' => count($fieldValues),
            ]);

            // Buscar el fieldValue específico
            foreach ($fieldValues as $fieldValue) {
                // Asegurarnos que sea un array y tenga los campos esperados
                if (
                    is_array($fieldValue) &&
                    isset($fieldValue['field']) &&
                    (int) $fieldValue['field'] === $fieldId &&
                    isset($fieldValue['contact']) &&
                    (int) $fieldValue['contact'] === $contactId
                ) {

                    \Log::debug('ActiveCampaignService: FieldValue encontrado', [
                        'id' => $fieldValue['id'] ?? 'n/a',
                        'field' => $fieldValue['field'] ?? 'n/a',
                        'contact' => $fieldValue['contact'] ?? 'n/a',
                    ]);

                    return $fieldValue;
                }
            }

            return null;
        } catch (\Exception $e) {
            \Log::error('ActiveCampaignService: Error en findFieldValueSafely', [
                'contact_id' => $contactId,
                'field_id' => $fieldId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Descubrir y mapear campos personalizados automáticamente
     */
    public function discoverAndMapCustomFields(): array
    {
        \Log::info('ActiveCampaignService: Descubriendo campos personalizados...');

        try {
            // Obtener todos los campos personalizados
            $customFields = $this->getAllCustomFields();

            \Log::info('ActiveCampaignService: Campos encontrados', [
                'total' => count($customFields),
            ]);

            $fieldMap = [];
            $availableFields = [];

            foreach ($customFields as $field) {
                if (!is_array($field) || !isset($field['id'], $field['title'])) {
                    continue;
                }

                $fieldId = (int) $field['id'];
                $fieldTitle = $field['title'];
                $fieldTag = $field['perstag'] ?? '';
                $fieldType = $field['type'] ?? 'text';

                // Guardar información del campo
                $availableFields[] = [
                    'id' => $fieldId,
                    'title' => $fieldTitle,
                    'perstag' => $fieldTag,
                    'type' => $fieldType,
                    'options' => $field['options'] ?? [],
                ];

                // Crear mapeo basado en el título (para referencia)
                $fieldMap[$fieldId] = [
                    'title' => $fieldTitle,
                    'perstag' => $fieldTag,
                    'type' => $fieldType,
                ];
            }

            // Ordenar por ID
            usort($availableFields, function ($a, $b) {
                return $a['id'] <=> $b['id'];
            });

            \Log::info('ActiveCampaignService: Campos disponibles', [
                'campos' => array_map(function ($field) {
                    return "ID: {$field['id']} - {$field['title']} ({$field['type']})";
                }, $availableFields),
            ]);

            return [
                'available_fields' => $availableFields,
                'field_map' => $fieldMap,
                'total_fields' => count($availableFields),
            ];

        } catch (\Exception $e) {
            \Log::error('ActiveCampaignService: Error descubriendo campos', [
                'error' => $e->getMessage(),
            ]);

            return [
                'available_fields' => [],
                'field_map' => [],
                'total_fields' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Encontrar campo por título o etiqueta
     */
    public function findFieldByTitleOrTag(string $search): ?array
    {
        $fields = $this->getAllCustomFields();

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $title = strtolower($field['title'] ?? '');
            $tag = strtolower($field['perstag'] ?? '');
            $searchLower = strtolower($search);

            if (str_contains($title, $searchLower) || str_contains($tag, $searchLower)) {
                return [
                    'id' => (int) $field['id'],
                    'title' => $field['title'] ?? '',
                    'perstag' => $field['perstag'] ?? '',
                    'type' => $field['type'] ?? 'text',
                ];
            }
        }

        return null;
    }

    /**
     * Validar configuración actual contra campos reales
     */
    public function validateFieldConfiguration(): array
    {
        $configMapping = config('activecampaign.field_mapping', []);
        $validationResults = [];

        \Log::info('ActiveCampaignService: Validando configuración de campos', [
            'config_mapping' => $configMapping,
        ]);

        // Obtener campos reales de ActiveCampaign
        $actualFields = $this->getAllCustomFields();
        $actualFieldMap = [];

        foreach ($actualFields as $field) {
            if (is_array($field) && isset($field['id'])) {
                $actualFieldMap[(int) $field['id']] = [
                    'title' => $field['title'] ?? '',
                    'perstag' => $field['perstag'] ?? '',
                ];
            }
        }

        // Validar cada campo en la configuración
        foreach ($configMapping as $fieldName => $fieldId) {
            if (!$fieldId) {
                $validationResults[$fieldName] = [
                    'status' => 'error',
                    'message' => 'ID no configurado',
                ];
                continue;
            }

            if (isset($actualFieldMap[$fieldId])) {
                $validationResults[$fieldName] = [
                    'status' => 'ok',
                    'configured_id' => $fieldId,
                    'actual_title' => $actualFieldMap[$fieldId]['title'],
                    'actual_perstag' => $actualFieldMap[$fieldId]['perstag'],
                ];
            } else {
                $validationResults[$fieldName] = [
                    'status' => 'error',
                    'message' => "ID {$fieldId} no existe en ActiveCampaign",
                    'configured_id' => $fieldId,
                ];
            }
        }

        // Campos existentes pero no configurados
        $unconfiguredFields = [];
        foreach ($actualFieldMap as $fieldId => $fieldInfo) {
            if (!in_array($fieldId, $configMapping, true)) {
                $unconfiguredFields[] = [
                    'id' => $fieldId,
                    'title' => $fieldInfo['title'],
                    'perstag' => $fieldInfo['perstag'],
                ];
            }
        }

        return [
            'validation_results' => $validationResults,
            'unconfigured_fields' => $unconfiguredFields,
            'actual_fields_total' => count($actualFieldMap),
        ];
    }
}