<?php

namespace App\Services\ActiveCampaign;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ActiveCampaignService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.activecampaign.endpoint')
            ?? throw new \Exception('ActiveCampaign endpoint not configured');

        $this->apiKey = config('services.activecampaign.token')
            ?? throw new \Exception('ActiveCampaign token not configured');
    }

    protected function client()
    {
        return Http::withHeaders([
            'Api-Token' => $this->apiKey,
            'Accept' => 'application/json',
        ])->baseUrl($this->baseUrl . '/api/3');
    }

    /**
     * Crear o actualizar contacto (campos básicos)
     */
    public function syncContact(array $data): ?int
    {
        try {

            $response = $this->client()->post('/contact/sync', [
                'contact' => [
                    'email' => $data['email'],
                    'firstName' => $data['first_name'],
                    'lastName' => $data['paternal_lastname'], // puedes dejar solo paterno aquí
                    'phone' => $data['phone'],

                    'fieldValues' => [
                        [
                            'field' => 18, // Apellido Paterno
                            'value' => $data['paternal_lastname'],
                        ],
                        [
                            'field' => 19, // Apellido Materno
                            'value' => $data['maternal_lastname'],
                        ],
                        [
                            'field' => 2, // Sexo
                            'value' => $data['gender'], // "Masculino" o "Femenino"
                        ],
                        [
                            'field' => 3, // Fecha de Nacimiento
                            'value' => $data['birth_date'], // formato Y-m-d
                        ],
                        [
                            'field' => 6, // Fecha de Registro
                            'value' => now()->format('Y-m-d'),
                        ],
                        [
                            'field' => 8, // País Teléfono
                            'value' => $data['phone_country'],
                        ],
                        [
                            'field' => 10, // Entidad Federativa
                            'value' => $data['state'],
                        ],
                    ],
                ],
            ]);


            if (!$response->successful()) {
                Log::error('AC: Error syncContact', [
                    'response' => $response->body(),
                ]);
                return null;
            }

            $contactId = $response->json()['contact']['id'] ?? null;

            Log::info('AC: Contacto sincronizado', [
                'contact_id' => $contactId,
            ]);

            return $contactId;

        } catch (\Throwable $e) {

            Log::error('AC: Excepción syncContact', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Agregar contacto a lista
     */
    public function addToList(int $contactId): void
    {
        try {

            $this->client()->post('/contactLists', [
                'contactList' => [
                    'contact' => $contactId,
                    'list' => config('services.activecampaign.list_new_users'),
                    'status' => 1,
                ],
            ]);

            Log::info('AC: Contacto agregado a lista', [
                'contact_id' => $contactId,
            ]);

        } catch (\Throwable $e) {
            Log::error('AC: Error addToList', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Agregar tag
     */
    public function addTag(int $contactId): void
    {
        try {

            $this->client()->post('/contactTags', [
                'contactTag' => [
                    'contact' => $contactId,
                    'tag' => config('services.activecampaign.tag_registro_web'),
                ],
            ]);

            Log::info('AC: Tag agregado', [
                'contact_id' => $contactId,
            ]);

        } catch (\Throwable $e) {
            Log::error('AC: Error addTag', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Flujo completo de nuevo registro
     */
    public function newRegistration(array $data): void
    {
        $contactId = $this->syncContact($data);

        if (!$contactId) {
            return;
        }

        $this->addToList($contactId);
        $this->addTag($contactId);
    }

    public function getFields(): array
    {
        $response = $this->client()->get('/fields');

        if (!$response->successful()) {
            Log::error('AC: Error obteniendo fields', [
                'response' => $response->body(),
            ]);
            return [];
        }

        return $response->json();
    }

    /**
     * Obtener todos los tags
     */
    public function getTags(): array
    {
        try {
            $allTags = [];
            $offset = 0;
            $limit = 100; // Máximo permitido por ActiveCampaign

            do {
                $response = $this->client()->get('/tags', [
                    'limit' => $limit,
                    'offset' => $offset
                ]);

                if (!$response->successful()) {
                    Log::error('AC: Error obteniendo tags', [
                        'response' => $response->body(),
                        'offset' => $offset
                    ]);
                    return $allTags; // Retorna lo que se haya obtenido hasta ahora
                }

                $data = $response->json();

                if (isset($data['tags']) && is_array($data['tags'])) {
                    $allTags = array_merge($allTags, $data['tags']);
                }

                $offset += $limit;

                // Verificar si hay más páginas
                $totalTags = $data['meta']['total'] ?? 0;

            } while ($offset < $totalTags);

            Log::info('AC: Tags obtenidos exitosamente', [
                'total' => count($allTags)
            ]);

            return $allTags;

        } catch (\Throwable $e) {
            Log::error('AC: Excepción getTags', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Crear un campo personalizado
     * @see https://developers.activecampaign.com/reference/create-a-custom-field-meta
     */
    public function createCustomField(array $data): ?array
    {
        try {
            // Mapeo de tipos de campo
            $fieldTypes = [
                'text' => 'text',
                'textarea' => 'textarea',
                'date' => 'date',
                'datetime' => 'datetime',
                'number' => 'number',
                'decimal' => 'number', // ActiveCampaign usa 'number' para decimales
                'dropdown' => 'dropdown',
                'radio' => 'radio',
                'checkbox' => 'checkbox',
                'multiselect' => 'multiselect',
                'hidden' => 'hidden',
            ];

            $type = $fieldTypes[$data['type']] ?? 'text';

            // Preparar el payload según la documentación [citation:2]
            $payload = [
                'field' => [
                    'title' => $data['title'],
                    'type' => $type,
                    'descript' => $data['description'] ?? '',
                    'perstag' => $this->generatePersTag($data['title']),
                    'visible' => $data['visible'] ?? true,
                    'show_in_list' => $data['show_in_list'] ?? true,
                    'ordernum' => $data['ordernum'] ?? 0,
                ]
            ];

            // Si es un campo con opciones (dropdown, radio, etc.)
            if (in_array($type, ['dropdown', 'radio', 'checkbox', 'multiselect']) && isset($data['options'])) {
                $payload['field']['options'] = $data['options'];
            }

            // Si es número, especificar decimales
            if ($type === 'number' && isset($data['decimal_places'])) {
                $payload['field']['decimal_places'] = $data['decimal_places'];
            }

            $response = $this->client()->post('/fields', $payload);

            if (!$response->successful()) {
                Log::error('AC: Error creando campo personalizado', [
                    'response' => $response->body(),
                    'data' => $data
                ]);
                return null;
            }

            $field = $response->json()['field'] ?? null;

            Log::info('AC: Campo personalizado creado', [
                'field_id' => $field['id'] ?? null,
                'title' => $data['title']
            ]);

            return $field;

        } catch (\Throwable $e) {
            Log::error('AC: Excepción createCustomField', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return null;
        }
    }

    /**
     * Buscar un campo por título (nombre)
     */
    public function findCustomFieldByTitle(string $title): ?array
    {
        $fields = $this->getCustomFields();

        foreach ($fields as $field) {
            if (strtolower($field['title']) === strtolower($title)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Obtener o crear un campo personalizado
     */
    public function getOrCreateCustomField(array $fieldData): ?array
    {
        // Buscar si ya existe
        $existingField = $this->findCustomFieldByTitle($fieldData['title']);

        if ($existingField) {
            Log::info('AC: Campo ya existe', [
                'field_id' => $existingField['id'],
                'title' => $fieldData['title']
            ]);
            return $existingField;
        }

        // Si no existe, crearlo
        return $this->createCustomField($fieldData);
    }

    /**
     * Crear múltiples campos personalizados de una vez
     */
    public function createMultipleCustomFields(array $fields): array
    {
        $results = [];

        foreach ($fields as $field) {
            $result = $this->getOrCreateCustomField($field);

            if ($result) {
                $results[$field['title']] = [
                    'id' => $result['id'],
                    'title' => $result['title'],
                    'type' => $result['type'],
                    'status' => 'exists_or_created'
                ];
            } else {
                $results[$field['title']] = [
                    'status' => 'failed',
                    'title' => $field['title']
                ];
            }
        }

        return $results;
    }

    /**
     * Obtener todos los campos personalizados
     */
    public function getCustomFields(): array
    {
        try {
            $allFields = [];
            $offset = 0;
            $limit = 100;

            do {
                $response = $this->client()->get('/fields', [
                    'limit' => $limit,
                    'offset' => $offset
                ]);

                if (!$response->successful()) {
                    Log::error('AC: Error obteniendo fields', [
                        'response' => $response->body()
                    ]);
                    return $allFields;
                }

                $data = $response->json();
                
                if (isset($data['fields']) && is_array($data['fields'])) {
                    $allFields = array_merge($allFields, $data['fields']);
                }

                $offset += $limit;
                $totalFields = $data['meta']['total'] ?? 0;
                
            } while ($offset < $totalFields);

            return $allFields;

        } catch (\Throwable $e) {
            Log::error('AC: Excepción getCustomFields', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
