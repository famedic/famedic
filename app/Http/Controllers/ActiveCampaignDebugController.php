<?php

namespace App\Http\Controllers;

use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Http\Request;

class ActiveCampaignFieldsController extends Controller
{
    protected ActiveCampaignService $service;

    public function __construct(ActiveCampaignService $service)
    {
        $this->service = $service;
    }

    /**
     * PREVIEW: Ver qué campos faltan crear (sin ejecutar la creación)
     */
    public function previewMissingFields()
    {
        // Obtener todos los campos existentes
        $existingFields = $this->service->getCustomFields();
        
        // Crear un array con los títulos existentes para búsqueda rápida
        $existingTitles = [];
        foreach ($existingFields as $field) {
            $existingTitles[strtolower(trim($field['title']))] = [
                'id' => $field['id'],
                'title' => $field['title'],
                'type' => $field['type']
            ];
        }

        // Definir los campos que necesitamos
        $requiredFields = [
            [
                'title' => 'Membresía Activa',
                'type' => 'dropdown',
                'description' => 'Estado actual de la membresía del contacto',
                'options' => ['Activa', 'Inactiva', 'Expirada', 'Pendiente', 'Cancelada']
            ],
            [
                'title' => 'Fecha Inicio Membresía',
                'type' => 'date',
                'description' => 'Fecha de inicio de la membresía actual'
            ],
            [
                'title' => 'Fecha Fin Membresía',
                'type' => 'date',
                'description' => 'Fecha de expiración de la membresía'
            ],
            [
                'title' => 'Total Compras Farmacia',
                'type' => 'number',
                'description' => 'Monto total acumulado en compras de farmacia',
                'decimal_places' => 2,
                'note' => 'Actualmente existe como "Compras de Farmacia" (ID: 21) de tipo text'
            ],
            [
                'title' => 'Total Compras Laboratorio',
                'type' => 'number',
                'description' => 'Monto total acumulado en compras de laboratorio',
                'decimal_places' => 2,
                'note' => 'Actualmente existe como "Compras de Laboratorio" (ID: 20) de tipo text'
            ],
            [
                'title' => 'Última Compra Monto',
                'type' => 'number',
                'description' => 'Monto de la última compra realizada',
                'decimal_places' => 2
            ]
        ];

        $preview = [];
        
        foreach ($requiredFields as $field) {
            $searchTitle = strtolower(trim($field['title']));
            
            // Buscar si existe (con diferentes variantes)
            $exists = false;
            $existingId = null;
            $existingType = null;
            $existingTitle = null;
            
            // Buscar por título exacto
            if (isset($existingTitles[$searchTitle])) {
                $exists = true;
                $existingId = $existingTitles[$searchTitle]['id'];
                $existingType = $existingTitles[$searchTitle]['type'];
                $existingTitle = $existingTitles[$searchTitle]['title'];
            }
            
            // Buscar variantes conocidas
            $variants = [
                'compras de farmacia' => ['total compras farmacia', 'compras farmacia'],
                'compras de laboratorio' => ['total compras laboratorio', 'compras laboratorio'],
            ];
            
            foreach ($variants as $key => $variantList) {
                if (str_contains($searchTitle, $key) || in_array($searchTitle, $variantList)) {
                    foreach ($variantList as $variant) {
                        if (isset($existingTitles[$variant])) {
                            $exists = true;
                            $existingId = $existingTitles[$variant]['id'];
                            $existingType = $existingTitles[$variant]['type'];
                            $existingTitle = $existingTitles[$variant]['title'];
                            break;
                        }
                    }
                }
            }

            $preview[$field['title']] = [
                'status' => $exists ? 'EXISTE' : '❌ FALTA CREAR',
                'action' => $exists ? 'Usar existente' : 'Crear nuevo',
                'existing_id' => $existingId,
                'existing_title' => $existingTitle,
                'existing_type' => $existingType,
                'required_type' => $field['type'],
                'type_match' => ($exists && $existingType === $field['type']) ? '✅' : '⚠️ Diferente',
                'description' => $field['description']
            ];
        }

        // Agregar campos existentes relevantes
        $existingRelevant = [];
        $relevantTitles = ['Apellido Paterno', 'Apellido Materno', 'Sexo', 'Fecha de Nacimiento', 
                          'Fecha de Registro', 'País Teléfono', 'Entidad Federativa'];
        
        foreach ($existingFields as $field) {
            if (in_array($field['title'], $relevantTitles)) {
                $existingRelevant[$field['title']] = [
                    'id' => $field['id'],
                    'type' => $field['type'],
                    'status' => '✅ EXISTE'
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'PREVIEW: Esto es lo que se creará cuando ejecutes POST /ac/fields/create-membership',
            'to_create' => $preview,
            'existing_relevant_fields' => $existingRelevant,
            'summary' => [
                'total_requeridos' => count($requiredFields),
                'ya_existen' => count(array_filter($preview, fn($item) => $item['status'] === 'EXISTE')),
                'faltan_crear' => count(array_filter($preview, fn($item) => $item['status'] === '❌ FALTA CREAR')),
            ],
            'instruction' => 'Para crear los campos faltantes, ejecuta: curl -X POST http://localhost:8000/ac/fields/create-membership'
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Listar todos los campos personalizados
     */
    public function index()
    {
        $fields = $this->service->getCustomFields();

        return response()->json([
            'success' => true,
            'total' => count($fields),
            'fields' => $fields
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Crear los campos de membresía y compras
     */
    public function createMembershipFields()
    {
        $results = [];

        // 1. Membresía Activa
        $results['Membresía Activa'] = $this->createFieldIfNotExists([
            'title' => 'Membresía Activa',
            'type' => 'dropdown',
            'description' => 'Estado actual de la membresía del contacto',
            'options' => [
                'Activa',
                'Inactiva',
                'Expirada',
                'Pendiente',
                'Cancelada'
            ],
            'show_in_list' => true,
            'ordernum' => 30
        ]);

        // 2. Fecha Inicio Membresía
        $results['Fecha Inicio Membresía'] = $this->createFieldIfNotExists([
            'title' => 'Fecha Inicio Membresía',
            'type' => 'date',
            'description' => 'Fecha de inicio de la membresía actual',
            'show_in_list' => true,
            'ordernum' => 31
        ]);

        // 3. Fecha Fin Membresía
        $results['Fecha Fin Membresía'] = $this->createFieldIfNotExists([
            'title' => 'Fecha Fin Membresía',
            'type' => 'date',
            'description' => 'Fecha de expiración de la membresía',
            'show_in_list' => true,
            'ordernum' => 32
        ]);

        // 4. Total Compras Farmacia (verificar si ya existe como "Compras de Farmacia")
        $farmaciaField = $this->service->findCustomFieldByTitle('Compras de Farmacia');
        if ($farmaciaField) {
            $results['Total Compras Farmacia'] = [
                'status' => 'exists',
                'id' => $farmaciaField['id'],
                'title' => $farmaciaField['title'],
                'type' => $farmaciaField['type'],
                'note' => 'Ya existe como "Compras de Farmacia". Puedes usar este campo.'
            ];
        } else {
            $results['Total Compras Farmacia'] = $this->createFieldIfNotExists([
                'title' => 'Total Compras Farmacia',
                'type' => 'number',
                'description' => 'Monto total acumulado en compras de farmacia',
                'decimal_places' => 2,
                'show_in_list' => true,
                'ordernum' => 33
            ]);
        }

        // 5. Total Compras Laboratorio (verificar si ya existe como "Compras de Laboratorio")
        $laboratorioField = $this->service->findCustomFieldByTitle('Compras de Laboratorio');
        if ($laboratorioField) {
            $results['Total Compras Laboratorio'] = [
                'status' => 'exists',
                'id' => $laboratorioField['id'],
                'title' => $laboratorioField['title'],
                'type' => $laboratorioField['type'],
                'note' => 'Ya existe como "Compras de Laboratorio". Puedes usar este campo.'
            ];
        } else {
            $results['Total Compras Laboratorio'] = $this->createFieldIfNotExists([
                'title' => 'Total Compras Laboratorio',
                'type' => 'number',
                'description' => 'Monto total acumulado en compras de laboratorio',
                'decimal_places' => 2,
                'show_in_list' => true,
                'ordernum' => 34
            ]);
        }

        // 6. Última Compra Monto
        $results['Última Compra Monto'] = $this->createFieldIfNotExists([
            'title' => 'Última Compra Monto',
            'type' => 'number',
            'description' => 'Monto de la última compra realizada',
            'decimal_places' => 2,
            'show_in_list' => true,
            'ordernum' => 35
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Proceso de verificación/creación de campos completado',
            'results' => $results,
            'config_suggestion' => [
                'fields' => [
                    'membresia_activa' => $this->extractId($results['Membresía Activa']),
                    'fecha_inicio_membresia' => $this->extractId($results['Fecha Inicio Membresía']),
                    'fecha_fin_membresia' => $this->extractId($results['Fecha Fin Membresía']),
                    'total_compras_farmacia' => $this->extractId($results['Total Compras Farmacia']) ?: '21',
                    'total_compras_laboratorio' => $this->extractId($results['Total Compras Laboratorio']) ?: '20',
                    'ultima_compra_monto' => $this->extractId($results['Última Compra Monto']),
                ]
            ]
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Helper para extraer ID de diferentes formatos de resultado
     */
    private function extractId($result)
    {
        if (is_array($result)) {
            return $result['id'] ?? ($result['field']['id'] ?? null);
        }
        return null;
    }

    /**
     * Helper para crear campo si no existe
     */
    private function createFieldIfNotExists(array $fieldData)
    {
        $existingField = $this->service->findCustomFieldByTitle($fieldData['title']);
        
        if ($existingField) {
            return [
                'status' => 'exists',
                'id' => $existingField['id'],
                'title' => $existingField['title'],
                'type' => $existingField['type']
            ];
        }

        $newField = $this->service->createCustomField($fieldData);
        
        if ($newField) {
            return [
                'status' => 'created',
                'id' => $newField['id'],
                'title' => $newField['title'],
                'type' => $newField['type']
            ];
        }

        return [
            'status' => 'failed',
            'title' => $fieldData['title']
        ];
    }

    /**
     * Obtener los IDs de todos los campos relevantes
     */
    public function getRelevantFields()
    {
        $allFields = $this->service->getCustomFields();
        
        $relevantTitles = [
            'Membresía Activa',
            'Fecha Inicio Membresía',
            'Fecha Fin Membresía',
            'Compras de Farmacia',
            'Total Compras Farmacia',
            'Compras de Laboratorio',
            'Total Compras Laboratorio',
            'Última Compra Monto',
            'Apellido Paterno',
            'Apellido Materno',
            'Sexo',
            'Fecha de Nacimiento',
            'Fecha de Registro',
            'País Teléfono',
            'Entidad Federativa'
        ];

        $relevantFields = [];
        
        foreach ($allFields as $field) {
            if (in_array($field['title'], $relevantTitles)) {
                $relevantFields[$field['title']] = [
                    'id' => $field['id'],
                    'type' => $field['type'],
                    'perstag' => $field['perstag'] ?? null
                ];
            }
        }

        return response()->json([
            'success' => true,
            'fields' => $relevantFields,
            'config_ready' => [
                'ACTIVECAMPAIGN_FIELD_MEMBRESIA_ACTIVA' => $relevantFields['Membresía Activa']['id'] ?? 'pendiente',
                'ACTIVECAMPAIGN_FIELD_FECHA_INICIO_MEMBRESIA' => $relevantFields['Fecha Inicio Membresía']['id'] ?? 'pendiente',
                'ACTIVECAMPAIGN_FIELD_FECHA_FIN_MEMBRESIA' => $relevantFields['Fecha Fin Membresía']['id'] ?? 'pendiente',
                'ACTIVECAMPAIGN_FIELD_TOTAL_FARMACIA' => $relevantFields['Compras de Farmacia']['id'] ?? $relevantFields['Total Compras Farmacia']['id'] ?? '21',
                'ACTIVECAMPAIGN_FIELD_TOTAL_LABORATORIO' => $relevantFields['Compras de Laboratorio']['id'] ?? $relevantFields['Total Compras Laboratorio']['id'] ?? '20',
                'ACTIVECAMPAIGN_FIELD_ULTIMA_COMPRA' => $relevantFields['Última Compra Monto']['id'] ?? 'pendiente',
                'ACTIVECAMPAIGN_FIELD_APELLIDO_PATERNO' => $relevantFields['Apellido Paterno']['id'] ?? '18',
                'ACTIVECAMPAIGN_FIELD_APELLIDO_MATERNO' => $relevantFields['Apellido Materno']['id'] ?? '19',
                'ACTIVECAMPAIGN_FIELD_SEXO' => $relevantFields['Sexo']['id'] ?? '2',
                'ACTIVECAMPAIGN_FIELD_FECHA_NACIMIENTO' => $relevantFields['Fecha de Nacimiento']['id'] ?? '3',
                'ACTIVECAMPAIGN_FIELD_FECHA_REGISTRO' => $relevantFields['Fecha de Registro']['id'] ?? '6',
                'ACTIVECAMPAIGN_FIELD_PAIS_TELEFONO' => $relevantFields['País Teléfono']['id'] ?? '8',
                'ACTIVECAMPAIGN_FIELD_ENTIDAD_FEDERATIVA' => $relevantFields['Entidad Federativa']['id'] ?? '10',
            ]
        ], 200, [], JSON_PRETTY_PRINT);
    }
}