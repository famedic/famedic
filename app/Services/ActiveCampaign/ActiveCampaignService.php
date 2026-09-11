<?php

namespace App\Services\ActiveCampaign;

use App\DTOs\ActiveCampaign\ActiveCampaignOperationResult;
use App\Exceptions\ActiveCampaignSyncException;
use App\Models\User;
use App\Services\ActiveCampaign\Concerns\HandlesBeneficiaryEvents;
use App\Services\ActiveCampaign\Concerns\HandlesCouponCreditEvents;
use App\Services\ActiveCampaign\Concerns\HandlesPromoEvents;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ActiveCampaignService
{
    use HandlesBeneficiaryEvents;
    use HandlesCouponCreditEvents;
    use HandlesPromoEvents;

    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $endpoint = config('services.activecampaign.endpoint')
            ?? throw new \Exception('ActiveCampaign endpoint not configured');

        // Aceptar endpoint configurado con o sin "/api/3"
        $endpoint = rtrim($endpoint, '/');
        if (str_ends_with($endpoint, '/api/3')) {
            $endpoint = substr($endpoint, 0, -strlen('/api/3'));
        }

        $this->baseUrl = $endpoint;

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

    protected function generatePersTag(string $title): string
    {
        // ActiveCampaign usa "perstag" como identificador tipo %MI_CAMPO%.
        $slug = mb_strtoupper(trim($title));
        $slug = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $slug);
        $slug = preg_replace('/\s+/', '_', $slug);
        $slug = preg_replace('/_+/', '_', $slug);
        $slug = trim($slug, '_');

        return '%' . $slug . '%';
    }

    /**
     * Crear o actualizar contacto (campos básicos)
     */
    public function syncContact(array $data): ?int
    {
        Log::info('AC: syncContact iniciado', ['email' => $data['email'] ?? null]);

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

            // En registros, el tag que queremos aplicar es RegistroNuevo (id=3)
            $tagRaw = config('services.activecampaign.tag_registro_nuevo', 3);
            $tagId = is_numeric($tagRaw) ? (int) $tagRaw : 0;
            if ($tagId <= 0) {
                // Si el env trae "RegistroNuevo" (nombre) en vez del ID, fallback.
                $tagId = 3;
            }

            $response = $this->client()->post('/contactTags', [
                'contactTag' => [
                    'contact' => $contactId,
                    'tag' => $tagId,
                ],
            ]);

            if (!$response->successful()) {
                Log::error('AC: Error addTag (RegistroNuevo)', [
                    'contact_id' => $contactId,
                    'tag_id' => $tagId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return;
            }

            Log::info('AC: Tag agregado (RegistroNuevo)', [
                'contact_id' => $contactId,
                'tag_id' => $tagId,
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
        Log::info('AC: newRegistration iniciado', ['email' => $data['email'] ?? null]);

        $contactId = $this->syncContact($data);

        if (!$contactId) {
            Log::warning('AC: newRegistration omitido — syncContact no devolvió contacto', ['email' => $data['email'] ?? null]);
            return;
        }

        $this->addToList($contactId);
        $this->addTag($contactId);
        Log::info('AC: newRegistration completado', ['contact_id' => $contactId, 'email' => $data['email'] ?? null]);
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
                'decimal' => 'number',
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

    /**
     * Buscar un contacto por email
     */
    public function findContactByEmail(string $email): ?array
    {
        try {

            $response = $this->client()->get('/contacts', [
                'email' => $email
            ]);

            if (!$response->successful()) {
                Log::warning('AC: findContactByEmail — respuesta no exitosa', ['email' => $email, 'status' => $response->status()]);
                return null;
            }

            $contacts = $response->json()['contacts'] ?? [];
            $contact = $contacts[0] ?? null;

            if (!$contact) {
                Log::debug('AC: findContactByEmail — contacto no encontrado', ['email' => $email]);
            }

            return $contact;
        } catch (\Throwable $e) {

            Log::error('AC: Error findContactByEmail', [
                'error' => $e->getMessage(),
                'email' => $email,
            ]);

            return null;
        }
    }

    /**
     * GET /contacts/{id} — ficha completa del contacto.
     *
     * @return array<string, mixed>|null Contacto AC o null si no existe / error.
     */
    public function getContact(int $contactId): ?array
    {
        try {
            $response = $this->client()->get("/contacts/{$contactId}");

            if (! $response->successful()) {
                Log::warning('AC: getContact falló', [
                    'contact_id' => $contactId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json()['contact'] ?? null;
        } catch (\Throwable $e) {
            Log::error('AC: Excepción getContact', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * GET /contacts/{id}/contactData — geo / ubicación / tracking metadata.
     *
     * @return array<string, mixed>|null
     */
    public function getContactData(int $contactId): ?array
    {
        try {
            $response = $this->client()->get("/contacts/{$contactId}/contactData");

            if (! $response->successful()) {
                Log::warning('AC: getContactData falló', [
                    'contact_id' => $contactId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $json = $response->json();

            return $json['contactDatum'] ?? $json['contactData'] ?? null;
        } catch (\Throwable $e) {
            Log::error('AC: Excepción getContactData', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * GET /contacts/{id}/contactTags
     *
     * @return list<array<string, mixed>>
     */
    public function getContactTags(int $contactId): array
    {
        return $this->getContactCollection($contactId, 'contactTags', 'contactTags');
    }

    /**
     * GET /contacts/{id}/contactLists
     *
     * @return list<array<string, mixed>>
     */
    public function getContactLists(int $contactId): array
    {
        return $this->getContactCollection($contactId, 'contactLists', 'contactLists');
    }

    /**
     * GET /contacts/{id}/fieldValues
     *
     * @return list<array<string, mixed>>
     */
    public function getContactFieldValues(int $contactId): array
    {
        return $this->getContactCollection($contactId, 'fieldValues', 'fieldValues');
    }

    /**
     * GET /contacts/{id}/contactAutomations
     *
     * @return list<array<string, mixed>>
     */
    public function getContactAutomations(int $contactId): array
    {
        return $this->getContactCollection($contactId, 'contactAutomations', 'contactAutomations');
    }

    /**
     * GET /contacts/{id}/scoreValues
     *
     * @return list<array<string, mixed>>
     */
    public function getContactScoreValues(int $contactId): array
    {
        return $this->getContactCollection($contactId, 'scoreValues', 'scoreValues');
    }

    /**
     * GET /activities?contact={id}
     *
     * Nota AC: las activities se generan al recuperar el contacto vía getContact().
     * El Mirror debe llamar getContact() antes de este método.
     *
     * @return array{activities: list<array<string, mixed>>, raw: array<string, mixed>}
     */
    public function getContactActivities(int $contactId): array
    {
        try {
            $response = $this->client()->get('/activities', [
                'contact' => $contactId,
                'orders[tstamp]' => 'DESC',
            ]);

            if (! $response->successful()) {
                Log::warning('AC: getContactActivities falló', [
                    'contact_id' => $contactId,
                    'status' => $response->status(),
                ]);

                return ['activities' => [], 'raw' => []];
            }

            $json = $response->json() ?? [];

            return [
                'activities' => is_array($json['activities'] ?? null) ? $json['activities'] : [],
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('AC: Excepción getContactActivities', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);

            return ['activities' => [], 'raw' => []];
        }
    }

    /**
     * Catálogo paginado de listas de la cuenta.
     *
     * @return list<array<string, mixed>>
     */
    public function getLists(): array
    {
        return $this->getPaginatedCollection('/lists', 'lists');
    }

    /**
     * Catálogo paginado de automatizaciones de la cuenta.
     *
     * @return list<array<string, mixed>>
     */
    public function getAutomations(): array
    {
        return $this->getPaginatedCollection('/automations', 'automations');
    }

    /**
     * Catálogo de lead scores de la cuenta.
     *
     * @return list<array<string, mixed>>
     */
    public function getScores(): array
    {
        return $this->getPaginatedCollection('/scores', 'scores');
    }

    /**
     * GET /users/{id} — usuario AC (owner / account manager).
     *
     * @return array<string, mixed>|null
     */
    public function getUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $response = $this->client()->get("/users/{$userId}");

            if (! $response->successful()) {
                Log::debug('AC: getUser falló', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json()['user'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('AC: Excepción getUser', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getContactCollection(int $contactId, string $pathSegment, string $jsonKey): array
    {
        try {
            $response = $this->client()->get("/contacts/{$contactId}/{$pathSegment}");

            if (! $response->successful()) {
                Log::warning("AC: getContactCollection {$pathSegment} falló", [
                    'contact_id' => $contactId,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $items = $response->json()[$jsonKey] ?? [];

            return is_array($items) ? array_values($items) : [];
        } catch (\Throwable $e) {
            Log::error("AC: Excepción getContactCollection {$pathSegment}", [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getPaginatedCollection(string $path, string $jsonKey): array
    {
        try {
            $all = [];
            $offset = 0;
            $limit = 100;

            do {
                $response = $this->client()->get($path, [
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

                if (! $response->successful()) {
                    Log::error("AC: Error obteniendo {$jsonKey}", [
                        'path' => $path,
                        'offset' => $offset,
                        'status' => $response->status(),
                    ]);

                    return $all;
                }

                $data = $response->json();
                $page = $data[$jsonKey] ?? [];
                if (is_array($page)) {
                    $all = array_merge($all, $page);
                }

                $offset += $limit;
                $total = (int) ($data['meta']['total'] ?? 0);
            } while ($offset < $total);

            return $all;
        } catch (\Throwable $e) {
            Log::error("AC: Excepción getPaginatedCollection {$jsonKey}", [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Agregar un tag a un contacto.
     * Returns a structured result so callers never assume success from a missing exception.
     */
    public function addTagToContact(int $contactId, int $tagId): ActiveCampaignOperationResult
    {
        $started = hrtime(true);

        try {
            $response = $this->client()->post('/contactTags', [
                'contactTag' => [
                    'contact' => $contactId,
                    'tag' => $tagId,
                ],
            ]);

            $httpStatus = $response->status();
            $body = $response->json() ?? $response->body();

            if (! $response->successful()) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'add_tag',
                    'resource' => 'contactTag',
                    'contact_id' => $contactId,
                    'tag_id' => $tagId,
                    'http_status' => $httpStatus,
                    'response' => $body,
                    'error' => 'add_tag_http_error',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => ActiveCampaignOperationResult::isRetryableHttpStatus($httpStatus),
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'add_tag',
                'resource' => 'contactTag',
                'contact_id' => $contactId,
                'tag_id' => $tagId,
                'http_status' => $httpStatus,
                'response' => $body,
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            Log::info('AC: Tag agregado', [
                'contact_id' => $contactId,
                'tag_id' => $tagId,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'add_tag',
                'resource' => 'contactTag',
                'contact_id' => $contactId,
                'tag_id' => $tagId,
                'http_status' => null,
                'response' => null,
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            Log::error('AC: Error addTagToContact', [
                'error' => $e->getMessage(),
            ]);

            return $result;
        }
    }

    /**
     * Crear una orden e-commerce en ActiveCampaign.
     */
    public function createOrder(array $data): ActiveCampaignOperationResult
    {
        $started = hrtime(true);

        try {
            $response = $this->client()->post('/ecomOrders', [
                'order' => $data,
            ]);

            $httpStatus = $response->status();
            $body = $response->json() ?? $response->body();

            if (! $response->successful()) {
                Log::error('AC: Error creando orden', [
                    'response' => $response->body(),
                    'data' => $data,
                ]);

                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'create_ecom_order',
                    'resource' => 'ecomOrder',
                    'http_status' => $httpStatus,
                    'response' => $body,
                    'error' => 'create_ecom_order_http_error',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => ActiveCampaignOperationResult::isRetryableHttpStatus($httpStatus),
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            Log::info('AC: Orden registrada', [
                'external_id' => $data['externalid'] ?? null,
            ]);

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'create_ecom_order',
                'resource' => 'ecomOrder',
                'http_status' => $httpStatus,
                'response' => $body,
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            return $result;
        } catch (\Throwable $e) {
            Log::error('AC: Excepción createOrder', [
                'error' => $e->getMessage(),
            ]);

            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'create_ecom_order',
                'resource' => 'ecomOrder',
                'http_status' => null,
                'response' => null,
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            return $result;
        }
    }

    /**
     * Crear una orden para una compra de laboratorio.
     */
    public function laboratoryPurchase($purchase): ActiveCampaignOperationResult
    {
        $started = hrtime(true);
        Log::info('AC: laboratoryPurchase iniciado', ['purchase_id' => $purchase->id]);

        try {
            $purchase->loadMissing(['customer.user', 'laboratoryPurchaseItems']);
            $email = $purchase->customer->user->email ?? null;

            if (! is_string($email) || trim($email) === '') {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'laboratoryPurchase',
                    'resource' => 'ecomOrder',
                    'error' => 'missing_email',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => false,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $products = $purchase->laboratoryPurchaseItems->map(function ($item) {
                return [
                    'name' => $item->name,
                    'price' => $item->price_cents / 100,
                    'quantity' => 1,
                    'category' => 'Laboratorio',
                ];
            })->toArray();

            $orderResult = $this->createOrder([
                'externalid' => 'LAB-'.$purchase->id,
                'email' => $email,
                'currency' => 'MXN',
                'totalPrice' => $purchase->total_cents / 100,
                'orderDate' => $purchase->paid_at ?? now(),
                'connectionid' => 1,
                'products' => $products,
            ]);

            if (! $orderResult->success) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'laboratoryPurchase',
                    'resource' => 'ecomOrder',
                    'http_status' => $orderResult->httpStatus,
                    'response' => [
                        'create_order' => $orderResult->toArray(),
                    ],
                    'error' => $orderResult->error ?? 'laboratory_purchase_order_failed',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => $orderResult->retryable,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $tagId = (int) config('services.activecampaign.tag_laboratory_purchase_completed', 18);
            $tagResult = null;
            $contactId = null;

            if ($tagId > 0) {
                $contactResult = $this->getContactIdByEmailPublic($email);
                if (! $contactResult->success || ! $contactResult->contactId) {
                    $result = ActiveCampaignOperationResult::failure([
                        'operation' => 'laboratoryPurchase',
                        'resource' => 'contactTag',
                        'http_status' => $contactResult->httpStatus,
                        'response' => [
                            'create_order' => $orderResult->toArray(),
                            'resolve_contact' => $contactResult->toArray(),
                        ],
                        'error' => $contactResult->error ?? 'contact_not_found',
                        'duration_ms' => $this->elapsedMs($started),
                        'retryable' => $contactResult->retryable,
                    ]);
                    $this->logOperationResult($result);

                    return $result;
                }

                $contactId = $contactResult->contactId;
                $tagResult = $this->addTagToContact($contactId, $tagId);

                if (! $tagResult->success) {
                    $result = ActiveCampaignOperationResult::failure([
                        'operation' => 'laboratoryPurchase',
                        'resource' => 'contactTag',
                        'contact_id' => $contactId,
                        'tag_id' => $tagId,
                        'http_status' => $tagResult->httpStatus,
                        'response' => [
                            'create_order' => $orderResult->toArray(),
                            'add_tag' => $tagResult->toArray(),
                        ],
                        'error' => $tagResult->error ?? 'laboratory_purchase_tag_failed',
                        'duration_ms' => $this->elapsedMs($started),
                        'retryable' => $tagResult->retryable,
                    ]);
                    $this->logOperationResult($result);

                    return $result;
                }

                Log::info('AC: Tag compra laboratorio enviado', [
                    'email' => $email,
                    'purchase_id' => $purchase->id,
                    'tag_id' => $tagId,
                ]);
            }

            Log::info('AC: laboratoryPurchase completado', ['purchase_id' => $purchase->id, 'email' => $email]);

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'laboratoryPurchase',
                'resource' => 'ecomOrder',
                'contact_id' => $contactId,
                'tag_id' => $tagId > 0 ? $tagId : null,
                'http_status' => $orderResult->httpStatus,
                'response' => [
                    'create_order' => $orderResult->toArray(),
                    'add_tag' => $tagResult?->toArray(),
                ],
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            return $result;
        } catch (\Throwable $e) {
            Log::error('AC: Error laboratoryPurchase', [
                'error' => $e->getMessage(),
                'purchase_id' => $purchase->id,
            ]);

            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'laboratoryPurchase',
                'resource' => 'ecomOrder',
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            return $result;
        }
    }

    /**
     * Crear una orden para una compra de farmacia.
     */
    public function pharmacyPurchase($purchase): ActiveCampaignOperationResult
    {
        $started = hrtime(true);
        Log::info('AC: pharmacyPurchase iniciado', ['purchase_id' => $purchase->id]);

        try {
            $purchase->loadMissing(['customer.user', 'onlinePharmacyPurchaseItems']);
            $email = $purchase->customer->user->email ?? null;

            if (! is_string($email) || trim($email) === '') {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'pharmacyPurchase',
                    'resource' => 'ecomOrder',
                    'error' => 'missing_email',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => false,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $products = $purchase->onlinePharmacyPurchaseItems->map(function ($item) {
                return [
                    'name' => $item->name,
                    'price' => $item->price_cents / 100,
                    'quantity' => 1,
                    'category' => 'Farmacia',
                ];
            })->toArray();

            $orderResult = $this->createOrder([
                'externalid' => 'PHARM-'.$purchase->id,
                'email' => $email,
                'currency' => 'MXN',
                'totalPrice' => $purchase->total_cents / 100,
                'orderDate' => $purchase->created_at,
                'connectionid' => 1,
                'products' => $products,
            ]);

            if (! $orderResult->success) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'pharmacyPurchase',
                    'resource' => 'ecomOrder',
                    'http_status' => $orderResult->httpStatus,
                    'response' => ['create_order' => $orderResult->toArray()],
                    'error' => $orderResult->error ?? 'pharmacy_purchase_order_failed',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => $orderResult->retryable,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $tagId = (int) config('services.activecampaign.tag_pharmacy_purchase_completed', 17);
            $tagResult = null;
            $contactId = null;

            if ($tagId > 0) {
                $contactResult = $this->getContactIdByEmailPublic($email);
                if (! $contactResult->success || ! $contactResult->contactId) {
                    $result = ActiveCampaignOperationResult::failure([
                        'operation' => 'pharmacyPurchase',
                        'resource' => 'contactTag',
                        'http_status' => $contactResult->httpStatus,
                        'response' => [
                            'create_order' => $orderResult->toArray(),
                            'resolve_contact' => $contactResult->toArray(),
                        ],
                        'error' => $contactResult->error ?? 'contact_not_found',
                        'duration_ms' => $this->elapsedMs($started),
                        'retryable' => $contactResult->retryable,
                    ]);
                    $this->logOperationResult($result);

                    return $result;
                }

                $contactId = $contactResult->contactId;
                $tagResult = $this->addTagToContact($contactId, $tagId);

                if (! $tagResult->success) {
                    $result = ActiveCampaignOperationResult::failure([
                        'operation' => 'pharmacyPurchase',
                        'resource' => 'contactTag',
                        'contact_id' => $contactId,
                        'tag_id' => $tagId,
                        'http_status' => $tagResult->httpStatus,
                        'response' => [
                            'create_order' => $orderResult->toArray(),
                            'add_tag' => $tagResult->toArray(),
                        ],
                        'error' => $tagResult->error ?? 'pharmacy_purchase_tag_failed',
                        'duration_ms' => $this->elapsedMs($started),
                        'retryable' => $tagResult->retryable,
                    ]);
                    $this->logOperationResult($result);

                    return $result;
                }

                Log::info('AC: Tag compra farmacia enviado', [
                    'email' => $email,
                    'purchase_id' => $purchase->id,
                    'tag_id' => $tagId,
                ]);
            }

            Log::info('AC: pharmacyPurchase completado', ['purchase_id' => $purchase->id, 'email' => $email]);

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'pharmacyPurchase',
                'resource' => 'ecomOrder',
                'contact_id' => $contactId,
                'tag_id' => $tagId > 0 ? $tagId : null,
                'http_status' => $orderResult->httpStatus,
                'response' => [
                    'create_order' => $orderResult->toArray(),
                    'add_tag' => $tagResult?->toArray(),
                ],
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            return $result;
        } catch (\Throwable $e) {
            Log::error('AC: Error pharmacyPurchase', [
                'error' => $e->getMessage(),
                'purchase_id' => $purchase->id,
            ]);

            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'pharmacyPurchase',
                'resource' => 'ecomOrder',
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            return $result;
        }
    }

    /**
     * Activar una membresía (tag Membresía Activa).
     */
    public function activateMembership($subscription): ActiveCampaignOperationResult
    {
        $started = hrtime(true);
        $email = $subscription->customer->user->email ?? null;
        Log::info('AC: activateMembership iniciado', ['email' => $email, 'subscription_id' => $subscription->id ?? null]);

        try {
            if (! is_string($email) || trim($email) === '') {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'activateMembership',
                    'resource' => 'contactTag',
                    'error' => 'missing_email',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => false,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $contactResult = $this->getContactIdByEmailPublic($email);
            if (! $contactResult->success || ! $contactResult->contactId) {
                Log::warning('AC: activateMembership omitido — contacto no encontrado en AC', ['email' => $email]);

                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'activateMembership',
                    'resource' => 'contactTag',
                    'http_status' => $contactResult->httpStatus,
                    'response' => $contactResult->toArray(),
                    'error' => $contactResult->error ?? 'contact_not_found',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => $contactResult->retryable,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $tagId = 21; // Membresía Activa
            $tagResult = $this->addTagToContact($contactResult->contactId, $tagId);

            if (! $tagResult->success) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'activateMembership',
                    'resource' => 'contactTag',
                    'contact_id' => $contactResult->contactId,
                    'tag_id' => $tagId,
                    'http_status' => $tagResult->httpStatus,
                    'response' => $tagResult->toArray(),
                    'error' => $tagResult->error ?? 'activate_membership_tag_failed',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => $tagResult->retryable,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            Log::info('AC: activateMembership completado', [
                'contact_id' => $contactResult->contactId,
                'email' => $email,
            ]);

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'activateMembership',
                'resource' => 'contactTag',
                'contact_id' => $contactResult->contactId,
                'tag_id' => $tagId,
                'http_status' => $tagResult->httpStatus,
                'response' => $tagResult->toArray(),
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            return $result;
        } catch (\Throwable $e) {
            Log::error('AC: Error activateMembership', [
                'error' => $e->getMessage(),
                'email' => $email,
            ]);

            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'activateMembership',
                'resource' => 'contactTag',
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            return $result;
        }
    }

    /**
     * Terminar una membresía
     */
    public function endMembership($subscription): void
    {
        $email = $subscription->customer->user->email ?? null;
        Log::info('AC: endMembership iniciado', ['email' => $email, 'subscription_id' => $subscription->id ?? null]);

        try {

            $contact = $this->findContactByEmail($email);

            if (!$contact) {
                Log::warning('AC: endMembership omitido — contacto no encontrado en AC', ['email' => $email]);
                return;
            }

            $this->addTagToContact(
                $contact['id'],
                22 // Membresía Terminada
            );
            Log::info('AC: endMembership completado', ['contact_id' => $contact['id'], 'email' => $email]);
        } catch (\Throwable $e) {

            Log::error('AC: Error endMembership', [
                'error' => $e->getMessage(),
                'email' => $email,
            ]);
        }
    }

    /**
     * Obtener el ID de un contacto por email
     */
    protected function getContactIdByEmail(string $email): ?int
    {
        $contact = $this->findContactByEmail($email);

        return $contact['id'] ?? null;
    }

    /**
     * Variante pública para Jobs/servicios externos.
     * Returns a structured operation result (contact_id when success).
     */
    public function getContactIdByEmailPublic(string $email): ActiveCampaignOperationResult
    {
        $started = hrtime(true);

        try {
            $response = $this->client()->get('/contacts', [
                'email' => $email,
            ]);

            $httpStatus = $response->status();
            $body = $response->json() ?? $response->body();

            if (! $response->successful()) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'resolve_contact',
                    'resource' => 'contact',
                    'http_status' => $httpStatus,
                    'response' => $body,
                    'error' => 'resolve_contact_http_error',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => ActiveCampaignOperationResult::isRetryableHttpStatus($httpStatus),
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $contacts = is_array($body) ? ($body['contacts'] ?? []) : [];
            $contact = $contacts[0] ?? null;
            $contactId = isset($contact['id']) ? (int) $contact['id'] : null;

            if ($contactId === null || $contactId <= 0) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'resolve_contact',
                    'resource' => 'contact',
                    'http_status' => $httpStatus,
                    'response' => $body,
                    'error' => 'contact_not_found',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => false,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'resolve_contact',
                'resource' => 'contact',
                'contact_id' => $contactId,
                'http_status' => $httpStatus,
                'response' => $contact,
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            return $result;
        } catch (\Throwable $e) {
            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'resolve_contact',
                'resource' => 'contact',
                'http_status' => null,
                'response' => null,
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            Log::error('AC: Error getContactIdByEmailPublic', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }
    }

    /**
     * Resolver tagId por nombre (cacheado).
     * Returns a structured operation result (tag_id when success).
     */
    public function getTagIdByName(string $tagName): ActiveCampaignOperationResult
    {
        $started = hrtime(true);
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $tagName)));

        try {
            $tagId = Cache::remember("ac_tag_id_by_name:$normalized", now()->addHours(6), function () use ($normalized, $tagName) {
                $tags = $this->getTags();

                foreach ($tags as $tag) {
                    $name = $tag['tag'] ?? $tag['name'] ?? null;
                    if (! $name) {
                        continue;
                    }

                    $candidate = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
                    if ($candidate === $normalized) {
                        return (int) ($tag['id'] ?? 0) ?: null;
                    }
                }

                Log::warning('AC: tag no encontrado por nombre', [
                    'tag_name' => $tagName,
                ]);

                return null;
            });

            if ($tagId === null || (int) $tagId <= 0) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'resolve_tag',
                    'resource' => 'tag',
                    'http_status' => null,
                    'response' => ['tag_name' => $tagName],
                    'error' => 'tag_not_found',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => false,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'resolve_tag',
                'resource' => 'tag',
                'tag_id' => (int) $tagId,
                'http_status' => null,
                'response' => ['tag_name' => $tagName, 'tag_id' => (int) $tagId],
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            return $result;
        } catch (\Throwable $e) {
            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'resolve_tag',
                'resource' => 'tag',
                'http_status' => null,
                'response' => ['tag_name' => $tagName],
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            return $result;
        }
    }

    public function addTagToContactByName(int $contactId, string $tagName): void
    {
        $tagResult = $this->getTagIdByName($tagName);

        if (! $tagResult->success || ! $tagResult->tagId) {
            Log::warning('AC: omitiendo addTagToContactByName — tag_id no resuelto', [
                'contact_id' => $contactId,
                'tag_name' => $tagName,
                'operation_result' => $tagResult->toArray(),
            ]);

            return;
        }

        $this->addTagToContact($contactId, $tagResult->tagId);
    }

    /**
     * Registrar una compra completada (ecom order genérico).
     */
    public function completedPurchase(string $email, string $externalId, float $total, array $products, string $category): ActiveCampaignOperationResult
    {
        $started = hrtime(true);

        try {
            $orderResult = $this->createOrder([
                'externalid' => $externalId,
                'email' => $email,
                'currency' => 'MXN',
                'totalPrice' => $total,
                'orderDate' => now()->toIso8601String(),
                'connectionid' => 1,
                'products' => $products,
            ]);

            if (! $orderResult->success) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'completedPurchase',
                    'resource' => 'ecomOrder',
                    'http_status' => $orderResult->httpStatus,
                    'response' => $orderResult->toArray(),
                    'error' => $orderResult->error ?? 'completed_purchase_failed',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => $orderResult->retryable,
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            Log::info('AC: Compra registrada', [
                'email' => $email,
                'external_id' => $externalId,
                'category' => $category,
            ]);

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'completedPurchase',
                'resource' => 'ecomOrder',
                'http_status' => $orderResult->httpStatus,
                'response' => [
                    'category' => $category,
                    'create_order' => $orderResult->toArray(),
                ],
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            return $result;
        } catch (\Throwable $e) {
            Log::error('AC: Error completedPurchase', [
                'error' => $e->getMessage(),
            ]);

            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'completedPurchase',
                'resource' => 'ecomOrder',
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            return $result;
        }
    }

    /**
     * Registrar un producto agregado al carrito
     */
    public function cartAdded(string $email): void
    {
        Log::info('AC: cartAdded ejecutado', ['email' => $email]);
        $this->tagByEmail($email, 19);
    }

    /**
     * Registrar un carrito abandonado
     */
    public function cartAbandoned(string $email): void
    {
        Log::info('AC: cartAbandoned iniciado', ['email' => $email]);

        $contactId = $this->getContactIdByEmail($email);

        if (!$contactId) {
            Log::warning('AC: cartAbandoned omitido — contacto no encontrado en AC', ['email' => $email]);
            return;
        }

        $this->addTagToContact($contactId, 20);
        Log::info('AC: cartAbandoned completado', ['contact_id' => $contactId, 'email' => $email]);
    }

    /**
     * Registrar una membresía activada
     */
    public function membershipActivated(string $email): void
    {
        Log::info('AC: membershipActivated iniciado', ['email' => $email]);

        $contactId = $this->getContactIdByEmail($email);

        if (!$contactId) {
            Log::warning('AC: membershipActivated omitido — contacto no encontrado en AC', ['email' => $email]);
            return;
        }

        $this->addTagToContact($contactId, 21);
        Log::info('AC: membershipActivated completado', ['contact_id' => $contactId, 'email' => $email]);
    }

    /**
     * Registrar una membresía terminada
     */
    public function membershipEnded(string $email): void
    {
        Log::info('AC: membershipEnded iniciado', ['email' => $email]);

        $contactId = $this->getContactIdByEmail($email);

        if (!$contactId) {
            Log::warning('AC: membershipEnded omitido — contacto no encontrado en AC', ['email' => $email]);
            return;
        }

        $this->addTagToContact($contactId, 22);
        Log::info('AC: membershipEnded completado', ['contact_id' => $contactId, 'email' => $email]);
    }

    /**
     * Registrar un paciente agregado al laboratorio
     */
    public function laboratoryPatientAdded(string $email): void
    {
        Log::info('AC: laboratoryPatientAdded iniciado', ['email' => $email]);

        $contactId = $this->getContactIdByEmail($email);

        if (!$contactId) {
            Log::warning('AC: laboratoryPatientAdded omitido — contacto no encontrado en AC', ['email' => $email]);
            return;
        }

        $this->addTagToContact($contactId, 23);
        Log::info('AC: laboratoryPatientAdded completado', ['contact_id' => $contactId, 'email' => $email]);
    }

    /**
     * Registrar resultados disponibles
     */
    public function resultsAvailable(string $email): void
    {
        Log::info('AC: resultsAvailable iniciado', ['email' => $email]);

        $contactId = $this->getContactIdByEmail($email);

        if (!$contactId) {
            Log::warning('AC: resultsAvailable omitido — contacto no encontrado en AC', ['email' => $email]);
            return;
        }

        $this->addTagToContact($contactId, 24);
        Log::info('AC: resultsAvailable completado', ['contact_id' => $contactId, 'email' => $email]);
    }

    /**
     * Registrar una factura disponible
     */
    public function invoiceAvailable(string $email): void
    {
        Log::info('AC: invoiceAvailable iniciado', ['email' => $email]);

        $contactId = $this->getContactIdByEmail($email);

        if (!$contactId) {
            Log::warning('AC: invoiceAvailable omitido — contacto no encontrado en AC', ['email' => $email]);
            return;
        }

        $this->addTagToContact($contactId, 25);
        Log::info('AC: invoiceAvailable completado', ['contact_id' => $contactId, 'email' => $email]);
    }

    public function sampleCollected(string $email): void
    {
        Log::info('AC: Cita confirmed iniciado', ['email' => $email]);

        $contactId = $this->getContactIdByEmail($email);

        if (!$contactId) {
            Log::warning('AC: Cita confirmed omitido — contacto no encontrado en AC', ['email' => $email]);
            return;
        }

        $this->addTagToContact($contactId, 24);
        Log::info('AC: Cita confirmed completado', ['contact_id' => $contactId, 'email' => $email]);
    }

    public function patientCreated($contact): void
    {
        $email = $contact->customer->user->email ?? null;
        Log::info('AC: patientCreated iniciado', ['contact_id' => $contact->id, 'email' => $email]);

        try {
            // 1. Asegurar contacto principal
            $contactId = $this->getContactIdByEmail($email);

            if (!$contactId) {
                Log::info('AC: patientCreated — contacto no en AC, sincronizando', ['email' => $email]);
                $user = $contact->customer->user;
                $contactId = $this->syncContact([
                    'email' => $email,
                    'first_name' => $user->name ?? '',
                    'paternal_lastname' => $user->paternal_lastname ?? '',
                    'maternal_lastname' => $user->maternal_lastname ?? '',
                    'phone' => $user->phone ?? '',
                    'gender' => $contact->gender?->value ?? '',
                    'birth_date' => $contact->birth_date?->format('Y-m-d') ?? '',
                    'phone_country' => '',
                    'state' => '',
                ]);
            }

            if (!$contactId) {
                Log::warning('AC: patientCreated omitido — no se pudo obtener/crear contacto en AC', ['email' => $email]);
                return;
            }

            // 2. Tag
            $this->addTagToContact($contactId, 23);

            // 3. Evento
            /*$this->trackEvent($email, 'patient_created', [
                'patient_name' => $contact->name,
                'patient_id' => $contact->id,
            ]);*/

            Log::info('AC: patientCreated completado', ['contact_id' => $contactId, 'patient_id' => $contact->id, 'email' => $email]);
        } catch (\Throwable $e) {
            Log::error('AC: Error patientCreated', [
                'error' => $e->getMessage(),
                'contact_id' => $contact->id,
                'email' => $email,
            ]);
        }
    }

    protected function tagByEmail(string $email, int $tagId): void
    {
        $contactId = $this->getContactIdByEmail($email);

        if (!$contactId) {
            Log::warning('AC: tagByEmail omitido — contacto no encontrado', ['email' => $email, 'tag_id' => $tagId]);
            return;
        }

        $this->addTagToContact($contactId, $tagId);
    }

    public function trackEvent(string $email, string $eventName, array $eventData = []): void
    {
        if (! config('services.activecampaign.track_events')) {
            Log::info('AC: trackEvent deshabilitado', [
                'event' => $eventName,
                'email' => $email,
            ]);

            return;
        }

        $result = $this->trackEventResult($email, $eventName, $eventData);

        if (! $result->success) {
            Log::error('AC: Error trackEvent', [
                'response' => $result->response,
                'event' => $eventName,
                'email' => $email,
                'error' => $result->error,
            ]);
        }
    }

    /**
     * Variante estructurada para outbox/jobs que necesitan saber si el envío fue exitoso.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function trackEventResult(string $email, string $eventName, array $eventData = []): ActiveCampaignOperationResult
    {
        $started = hrtime(true);
        $email = trim($email);

        if ($email === '') {
            return ActiveCampaignOperationResult::failure([
                'operation' => 'track_event',
                'resource' => 'site_event',
                'http_status' => null,
                'response' => null,
                'error' => 'missing_email',
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => false,
            ]);
        }

        $accountId = config('services.activecampaign.account_id');
        $eventKey = config('services.activecampaign.event_key');

        if (! is_string($accountId) || trim($accountId) === '' || ! is_string($eventKey) || trim($eventKey) === '') {
            return ActiveCampaignOperationResult::failure([
                'operation' => 'track_event',
                'resource' => 'site_event',
                'http_status' => null,
                'response' => null,
                'error' => 'site_event_not_configured',
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => false,
            ]);
        }

        try {
            $response = Http::asForm()->post('https://trackcmp.net/event', [
                'actid' => $accountId,
                'key' => $eventKey,
                'event' => $eventName,
                'eventdata' => json_encode([
                    'email' => $email,
                    ...$eventData,
                ]),
            ]);

            $httpStatus = $response->status();
            $body = $response->json() ?? $response->body();

            if (! $response->successful()) {
                return ActiveCampaignOperationResult::failure([
                    'operation' => 'track_event',
                    'resource' => 'site_event',
                    'http_status' => $httpStatus,
                    'response' => $body,
                    'error' => 'track_event_http_error',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => ActiveCampaignOperationResult::isRetryableHttpStatus($httpStatus),
                ]);
            }

            Log::info('AC: Evento registrado correctamente', [
                'event' => $eventName,
                'email' => $email,
            ]);

            return ActiveCampaignOperationResult::success([
                'operation' => 'track_event',
                'resource' => 'site_event',
                'http_status' => $httpStatus,
                'response' => $body,
                'duration_ms' => $this->elapsedMs($started),
            ]);
        } catch (\Throwable $e) {
            Log::error('AC: Excepción trackEvent', [
                'error' => $e->getMessage(),
                'event' => $eventName,
            ]);

            return ActiveCampaignOperationResult::failure([
                'operation' => 'track_event',
                'resource' => 'site_event',
                'http_status' => null,
                'response' => null,
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
        }
    }

    /**
     * Outbox Fase 3: site event de carrito vía trackcmp.net.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleOutboundCartSiteEvent(array $payload): void
    {
        if (! config('services.activecampaign.cart_site_events_enabled', false)) {
            throw new ActiveCampaignSyncException('AC outbound cart site_event deshabilitado.');
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $eventName = trim((string) ($payload['event_name'] ?? ''));

        if ($email === '' || $eventName === '') {
            throw new ActiveCampaignSyncException('AC outbound cart site_event requiere email y event_name.');
        }

        $eventData = is_array($payload['event_data'] ?? null) ? $payload['event_data'] : [];

        $result = $this->trackEventResult($email, $eventName, $eventData);

        if (! $result->success) {
            throw new ActiveCampaignSyncException(
                'AC trackEvent falló ('.$eventName.'): '.($result->error ?? 'unknown')
            );
        }
    }

    public function enabled(): bool
    {
        return (bool) config('services.activecampaign.enabled', true);
    }

    public function couponsEnabled(): bool
    {
        return $this->enabled()
            && (bool) config('services.activecampaign.coupons_enabled', true);
    }

    public function couponTag(string $key): ?string
    {
        $value = data_get(config('services.activecampaign.tags'), $key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    public function couponField(string $key): ?string
    {
        $value = config('services.activecampaign.fields.'.$key);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ensureContactIdForCouponPayload(array $payload): int
    {
        $email = $payload['email'] ?? null;
        if (! is_string($email) || trim($email) === '') {
            throw new ActiveCampaignSyncException('AC coupon event: email requerido en payload.');
        }

        $contactId = $this->getContactIdByEmail($email);
        if ($contactId) {
            return $contactId;
        }

        $userId = $payload['user_id'] ?? null;
        if ($userId) {
            $user = User::query()->find($userId);
            if ($user) {
                $syncedId = $this->syncContactForUser($user);
                if ($syncedId) {
                    return $syncedId;
                }
            }
        }

        throw new ActiveCampaignSyncException("AC coupon event: no se pudo asegurar contacto para {$email}.");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ensureContactIdForBeneficiaryPayload(array $payload): int
    {
        $email = $payload['email'] ?? null;
        if (! is_string($email) || trim($email) === '') {
            throw new ActiveCampaignSyncException('AC beneficiary event: email requerido en payload.');
        }

        $contactId = $this->getContactIdByEmail($email);
        if ($contactId) {
            return $contactId;
        }

        $syncedId = $this->syncContactForBeneficiary($payload);
        if ($syncedId) {
            return $syncedId;
        }

        throw new ActiveCampaignSyncException("AC beneficiary event: no se pudo asegurar contacto para {$email}.");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncContactForBeneficiary(array $payload): ?int
    {
        $email = $payload['email'] ?? null;
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return $this->syncContact([
            'email' => $email,
            'first_name' => (string) ($payload['first_name'] ?? ''),
            'paternal_lastname' => (string) ($payload['paternal_lastname'] ?? ''),
            'maternal_lastname' => (string) ($payload['maternal_lastname'] ?? ''),
            'phone' => '',
            'gender' => '',
            'birth_date' => '',
            'phone_country' => '',
            'state' => '',
        ]);
    }

    public function syncContactForUser(User $user): ?int
    {
        return $this->syncContact([
            'email' => $user->email,
            'first_name' => $user->name ?? '',
            'paternal_lastname' => $user->paternal_lastname ?? '',
            'maternal_lastname' => $user->maternal_lastname ?? '',
            'phone' => $user->phone ?? '',
            'gender' => $user->formatted_gender ?? ($user->gender?->value ?? ''),
            'birth_date' => $user->birth_date?->format('Y-m-d') ?? '',
            'phone_country' => $user->phone_country ?? '',
            'state' => $user->state ?? '',
        ]);
    }

    public function resolveCouponTagId(string $dottedKey): ?int
    {
        $tag = $this->couponTag($dottedKey);
        if ($tag === null || $tag === '') {
            return null;
        }

        if (ctype_digit($tag)) {
            return (int) $tag;
        }

        $result = $this->getTagIdByName($tag);

        return $result->success ? $result->tagId : null;
    }

    public function addCouponTagByKey(int $contactId, string $dottedKey): void
    {
        $tagId = $this->resolveCouponTagId($dottedKey);
        if (! $tagId) {
            Log::warning('AC: omitiendo tag — no resuelto', [
                'contact_id' => $contactId,
                'tag_key' => $dottedKey,
            ]);

            return;
        }

        $this->addTagToContactOrFail($contactId, $tagId);
    }

    public function removeCouponTagByKey(int $contactId, string $dottedKey): void
    {
        $tagId = $this->resolveCouponTagId($dottedKey);
        if (! $tagId) {
            return;
        }

        $this->removeTagFromContactOrFail($contactId, $tagId);
    }

    /**
     * Outbox Fase 2: tag_add de carrito vía activecampaign_dispatches.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleOutboundCartTagAdd(array $payload): void
    {
        $email = trim((string) ($payload['email'] ?? ''));

        if ($email === '') {
            throw new ActiveCampaignSyncException('AC outbound cart tag_add requiere email.');
        }

        $contact = is_array($payload['contact'] ?? null) ? $payload['contact'] : null;

        if ($contact !== null) {
            $this->syncContact($contact);
        }

        $tagId = (int) ($payload['tag_id'] ?? 0);

        if ($tagId <= 0) {
            throw new ActiveCampaignSyncException('AC outbound cart tag_add requiere tag_id configurado.');
        }

        $contactId = $this->getContactIdByEmail($email);

        if (! $contactId) {
            throw new ActiveCampaignSyncException("AC outbound cart tag_add: contacto no encontrado para {$email}.");
        }

        $this->addTagToContactOrFail($contactId, $tagId);
    }

    /**
     * Outbox Fase 2/3: tag_remove de carrito (solo si cart_tag_remove_enabled).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleOutboundCartTagRemove(array $payload): void
    {
        $email = trim((string) ($payload['email'] ?? ''));

        if ($email === '') {
            throw new ActiveCampaignSyncException('AC outbound cart tag_remove requiere email.');
        }

        $tagId = (int) ($payload['tag_id'] ?? 0);

        if ($tagId <= 0) {
            throw new ActiveCampaignSyncException('AC outbound cart tag_remove requiere tag_id configurado.');
        }

        $contactId = $this->getContactIdByEmail($email);

        if (! $contactId) {
            throw new ActiveCampaignSyncException("AC outbound cart tag_remove: contacto no encontrado para {$email}.");
        }

        $this->removeTagFromContactOrFail($contactId, $tagId);
    }

    public function addTagToContactOrFail(int $contactId, int $tagId): void
    {
        $result = $this->addTagToContact($contactId, $tagId);

        if (! $result->success) {
            throw new ActiveCampaignSyncException(
                "AC addTag falló (contact={$contactId}, tag={$tagId}): ".($result->error ?? 'unknown')
            );
        }
    }

    /**
     * Remove a tag from a contact without throwing.
     * success=true for deleted or already absent (idempotent).
     */
    public function removeTagFromContact(int $contactId, int $tagId): ActiveCampaignOperationResult
    {
        $started = hrtime(true);

        try {
            $listResponse = $this->client()->get("/contacts/{$contactId}/contactTags");
            $listStatus = $listResponse->status();
            $listBody = $listResponse->json() ?? $listResponse->body();

            if (! $listResponse->successful()) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'remove_tag',
                    'resource' => 'contactTag',
                    'contact_id' => $contactId,
                    'tag_id' => $tagId,
                    'http_status' => $listStatus,
                    'response' => $listBody,
                    'error' => 'list_contact_tags_http_error',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => ActiveCampaignOperationResult::isRetryableHttpStatus($listStatus),
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $contactTags = is_array($listBody) ? ($listBody['contactTags'] ?? []) : [];
            $contactTagId = null;

            foreach ($contactTags as $contactTag) {
                if ((int) ($contactTag['tag'] ?? 0) === $tagId) {
                    $contactTagId = (int) ($contactTag['id'] ?? 0);
                    break;
                }
            }

            if ($contactTagId === null || $contactTagId <= 0) {
                $result = ActiveCampaignOperationResult::success([
                    'operation' => 'already_removed',
                    'resource' => 'contactTag',
                    'contact_id' => $contactId,
                    'tag_id' => $tagId,
                    'http_status' => $listStatus,
                    'response' => ['already_absent' => true],
                    'duration_ms' => $this->elapsedMs($started),
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $deleteResponse = $this->client()->delete("/contactTags/{$contactTagId}");
            $deleteStatus = $deleteResponse->status();
            $deleteBody = $deleteResponse->json() ?? $deleteResponse->body();

            if (! $deleteResponse->successful()) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'remove_tag',
                    'resource' => 'contactTag',
                    'contact_id' => $contactId,
                    'tag_id' => $tagId,
                    'http_status' => $deleteStatus,
                    'response' => $deleteBody,
                    'error' => 'remove_tag_http_error',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => ActiveCampaignOperationResult::isRetryableHttpStatus($deleteStatus),
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'remove_tag',
                'resource' => 'contactTag',
                'contact_id' => $contactId,
                'tag_id' => $tagId,
                'http_status' => $deleteStatus,
                'response' => $deleteBody,
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            return $result;
        } catch (\Throwable $e) {
            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'remove_tag',
                'resource' => 'contactTag',
                'contact_id' => $contactId,
                'tag_id' => $tagId,
                'http_status' => null,
                'response' => null,
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            return $result;
        }
    }

    /**
     * OrFail wrapper: returns structured result, throws when the remove operation failed.
     * Idempotent absences (already_removed) are treated as success and do not throw.
     */
    public function removeTagFromContactOrFail(int $contactId, int $tagId): ActiveCampaignOperationResult
    {
        $result = $this->removeTagFromContact($contactId, $tagId);

        if (! $result->success) {
            throw new ActiveCampaignSyncException(
                "AC removeTag falló (contact={$contactId}, tag={$tagId}): ".($result->error ?? 'unknown')
            );
        }

        return $result;
    }

    /**
     * Check whether a contact already has a tag. Structured result for payment automation.
     */
    public function contactHasTag(int $contactId, int $tagId): ActiveCampaignOperationResult
    {
        $started = hrtime(true);

        try {
            $response = $this->client()->get("/contacts/{$contactId}/contactTags");
            $httpStatus = $response->status();
            $body = $response->json() ?? $response->body();

            if (! $response->successful()) {
                $result = ActiveCampaignOperationResult::failure([
                    'operation' => 'check_contact_tag',
                    'resource' => 'contactTag',
                    'contact_id' => $contactId,
                    'tag_id' => $tagId,
                    'http_status' => $httpStatus,
                    'response' => $body,
                    'error' => 'list_contact_tags_http_error',
                    'duration_ms' => $this->elapsedMs($started),
                    'retryable' => ActiveCampaignOperationResult::isRetryableHttpStatus($httpStatus),
                ]);
                $this->logOperationResult($result);

                return $result;
            }

            $contactTags = is_array($body) ? ($body['contactTags'] ?? []) : [];
            $hasTag = false;

            foreach ($contactTags as $contactTag) {
                if ((int) ($contactTag['tag'] ?? 0) === $tagId) {
                    $hasTag = true;
                    break;
                }
            }

            $result = ActiveCampaignOperationResult::success([
                'operation' => 'check_contact_tag',
                'resource' => 'contactTag',
                'contact_id' => $contactId,
                'tag_id' => $tagId,
                'http_status' => $httpStatus,
                'response' => ['has_tag' => $hasTag],
                'duration_ms' => $this->elapsedMs($started),
            ]);
            $this->logOperationResult($result);

            return $result;
        } catch (\Throwable $e) {
            $result = ActiveCampaignOperationResult::failure([
                'operation' => 'check_contact_tag',
                'resource' => 'contactTag',
                'contact_id' => $contactId,
                'tag_id' => $tagId,
                'http_status' => null,
                'response' => null,
                'error' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'retryable' => true,
            ]);
            $this->logOperationResult($result);

            return $result;
        }
    }

    protected function logOperationResult(ActiveCampaignOperationResult $result): void
    {
        Log::info('[ActiveCampaign Operation]', [
            'operation' => $result->operation,
            'duration_ms' => $result->durationMs,
            'http_status' => $result->httpStatus,
            'success' => $result->success,
            'retryable' => $result->retryable,
            'contact_id' => $result->contactId,
            'tag_id' => $result->tagId,
            'error' => $result->error,
            'resource' => $result->resource,
        ]);
    }

    protected function elapsedMs(int $startedHrtime): int
    {
        return (int) round((hrtime(true) - $startedHrtime) / 1_000_000);
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    public function applyCouponFieldUpdates(int $contactId, array $fields): void
    {
        foreach ($fields as $fieldKey => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $fieldId = $this->couponField($fieldKey);
            if ($fieldId === null || $fieldId === '') {
                continue;
            }

            $this->updateContactFieldValueOrFail($contactId, $fieldId, (string) $value);
        }
    }

    public function updateContactFieldValueOrFail(int $contactId, string|int $fieldId, string $value): void
    {
        $response = $this->client()->post('/fieldValues', [
            'fieldValue' => [
                'contact' => $contactId,
                'field' => (int) $fieldId,
                'value' => $value,
            ],
        ]);

        if (! $response->successful()) {
            throw new ActiveCampaignSyncException(
                "AC fieldValue falló (contact={$contactId}, field={$fieldId}): ".$response->body()
            );
        }
    }

    public function formatCentsFieldValue(mixed $cents): ?string
    {
        if ($cents === null || $cents === '') {
            return null;
        }

        return number_format(((int) $cents) / 100, 2, '.', '');
    }

    public function formatDateFieldValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public function formatDateTimeFieldValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
