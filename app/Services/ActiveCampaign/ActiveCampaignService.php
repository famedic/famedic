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

}
