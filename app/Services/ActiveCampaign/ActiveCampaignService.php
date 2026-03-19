<?php

namespace App\Services\ActiveCampaign;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ActiveCampaignService
{
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
     * Agregar un tag a un contacto
     */
    public function addTagToContact(int $contactId, int $tagId): void
    {
        try {

            $this->client()->post('/contactTags', [
                'contactTag' => [
                    'contact' => $contactId,
                    'tag' => $tagId,
                ],
            ]);

            Log::info('AC: Tag agregado', [
                'contact_id' => $contactId,
                'tag_id' => $tagId
            ]);
        } catch (\Throwable $e) {

            Log::error('AC: Error addTagToContact', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Crear una orden
     */
    public function createOrder(array $data): void
    {
        try {

            $response = $this->client()->post('/ecomOrders', [
                'order' => $data
            ]);

            if (!$response->successful()) {

                Log::error('AC: Error creando orden', [
                    'response' => $response->body(),
                    'data' => $data
                ]);

                return;
            }

            Log::info('AC: Orden registrada', [
                'external_id' => $data['externalid'],
            ]);
        } catch (\Throwable $e) {

            Log::error('AC: Excepción createOrder', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Crear una orden para una compra de laboratorio
     */
    public function laboratoryPurchase($purchase): void
    {
        Log::info('AC: laboratoryPurchase iniciado', ['purchase_id' => $purchase->id]);

        try {

            $email = $purchase->customer->user->email;

            $products = $purchase->laboratoryPurchaseItems->map(function ($item) {

                return [
                    'name' => $item->name,
                    'price' => $item->price_cents / 100,
                    'quantity' => 1,
                    'category' => 'Laboratorio',
                ];
            })->toArray();

            $this->createOrder([
                'externalid' => 'LAB-' . $purchase->id,
                'email' => $email,
                'currency' => 'MXN',
                'totalPrice' => $purchase->total_cents / 100,
                'orderDate' => $purchase->paid_at ?? now(),
                'connectionid' => 1,
                'products' => $products,
            ]);

            Log::info('AC: laboratoryPurchase completado', ['purchase_id' => $purchase->id, 'email' => $email]);
        } catch (\Throwable $e) {

            Log::error('AC: Error laboratoryPurchase', [
                'error' => $e->getMessage(),
                'purchase_id' => $purchase->id
            ]);
        }
    }

    /**
     * Crear una orden para una compra de farmacia
     */
    public function pharmacyPurchase($purchase): void
    {
        Log::info('AC: pharmacyPurchase iniciado', ['purchase_id' => $purchase->id]);

        try {

            $email = $purchase->customer->user->email;

            $products = $purchase->onlinePharmacyPurchaseItems->map(function ($item) {

                return [
                    'name' => $item->name,
                    'price' => $item->price_cents / 100,
                    'quantity' => 1,
                    'category' => 'Farmacia',
                ];
            })->toArray();

            $this->createOrder([
                'externalid' => 'PHARM-' . $purchase->id,
                'email' => $email,
                'currency' => 'MXN',
                'totalPrice' => $purchase->total_cents / 100,
                'orderDate' => $purchase->created_at,
                'connectionid' => 1,
                'products' => $products,
            ]);

            Log::info('AC: pharmacyPurchase completado', ['purchase_id' => $purchase->id, 'email' => $email]);
        } catch (\Throwable $e) {

            Log::error('AC: Error pharmacyPurchase', [
                'error' => $e->getMessage(),
                'purchase_id' => $purchase->id
            ]);
        }
    }

    /**
     * Activar una membresía
     */
    public function activateMembership($subscription): void
    {
        $email = $subscription->customer->user->email ?? null;
        Log::info('AC: activateMembership iniciado', ['email' => $email, 'subscription_id' => $subscription->id ?? null]);

        try {

            $contact = $this->findContactByEmail($email);

            if (!$contact) {
                Log::warning('AC: activateMembership omitido — contacto no encontrado en AC', ['email' => $email]);
                return;
            }

            $this->addTagToContact(
                $contact['id'],
                21 // Membresía Activa
            );
            Log::info('AC: activateMembership completado', ['contact_id' => $contact['id'], 'email' => $email]);
        } catch (\Throwable $e) {

            Log::error('AC: Error activateMembership', [
                'error' => $e->getMessage(),
                'email' => $email,
            ]);
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
     */
    public function getContactIdByEmailPublic(string $email): ?int
    {
        return $this->getContactIdByEmail($email);
    }

    /**
     * Resolver tagId por nombre (cacheado).
     */
    public function getTagIdByName(string $tagName): ?int
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $tagName)));

        return Cache::remember("ac_tag_id_by_name:$normalized", now()->addHours(6), function () use ($normalized, $tagName) {
            $tags = $this->getTags();

            foreach ($tags as $tag) {
                $name = $tag['tag'] ?? $tag['name'] ?? null;
                if (!$name) {
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
    }

    public function addTagToContactByName(int $contactId, string $tagName): void
    {
        $tagId = $this->getTagIdByName($tagName);

        if (!$tagId) {
            Log::warning('AC: omitiendo addTagToContactByName — tag_id no resuelto', [
                'contact_id' => $contactId,
                'tag_name' => $tagName,
            ]);
            return;
        }

        $this->addTagToContact($contactId, $tagId);
    }

    /**
     * Registrar una compra completada
     */
    public function completedPurchase(string $email, string $externalId, float $total, array $products, string $category): void
    {
        try {

            $this->createOrder([
                'externalid' => $externalId,
                'email' => $email,
                'currency' => 'MXN',
                'totalPrice' => $total,
                'orderDate' => now()->toIso8601String(),
                'connectionid' => 1,
                'products' => $products,
            ]);

            Log::info('AC: Compra registrada', [
                'email' => $email,
                'external_id' => $externalId
            ]);
        } catch (\Throwable $e) {

            Log::error('AC: Error completedPurchase', [
                'error' => $e->getMessage()
            ]);
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
        if (!config('services.activecampaign.track_events')) {
            Log::info('AC: trackEvent deshabilitado', [
                'event' => $eventName,
                'email' => $email
            ]);
            return;
        }

        try {
            $response = Http::asForm()->post('https://trackcmp.net/event', [
                'actid' => config('services.activecampaign.account_id'),
                'key' => config('services.activecampaign.event_key'),
                'event' => $eventName,
                'eventdata' => json_encode([
                    'email' => $email,
                    ...$eventData
                ]),
            ]);

            if (!$response->successful()) {
                Log::error('AC: Error trackEvent', [
                    'response' => $response->body(),
                    'event' => $eventName,
                    'email' => $email
                ]);
                return;
            }

            Log::info('AC: Evento registrado correctamente', [
                'event' => $eventName,
                'email' => $email,
                'data' => $eventData
            ]);
        } catch (\Throwable $e) {
            Log::error('AC: Excepción trackEvent', [
                'error' => $e->getMessage(),
                'event' => $eventName
            ]);
        }
    }
}
