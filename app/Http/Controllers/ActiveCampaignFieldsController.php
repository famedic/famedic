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
     * Crear los campos de membresía y compras faltantes
     */
    public function createMembershipFields()
    {
        $results = [];

        // 1. Membresía Activa (no existe)
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

        // 2. Fecha Inicio Membresía (no existe)
        $results['Fecha Inicio Membresía'] = $this->createFieldIfNotExists([
            'title' => 'Fecha Inicio Membresía',
            'type' => 'date',
            'description' => 'Fecha de inicio de la membresía actual',
            'show_in_list' => true,
            'ordernum' => 31
        ]);

        // 3. Fecha Fin Membresía (no existe)
        $results['Fecha Fin Membresía'] = $this->createFieldIfNotExists([
            'title' => 'Fecha Fin Membresía',
            'type' => 'date',
            'description' => 'Fecha de expiración de la membresía',
            'show_in_list' => true,
            'ordernum' => 32
        ]);

        // 4. Total Compras Farmacia (existe pero es TEXT, lo dejamos como está por ahora)
        $results['Total Compras Farmacia'] = [
            'status' => 'exists',
            'id' => '21',
            'title' => 'Compras de Farmacia',
            'type' => 'text',
            'note' => 'Ya existe como campo de texto. Recomiendo usarlo como está.'
        ];

        // 5. Total Compras Laboratorio (existe pero es TEXT)
        $results['Total Compras Laboratorio'] = [
            'status' => 'exists',
            'id' => '20',
            'title' => 'Compras de Laboratorio',
            'type' => 'text',
            'note' => 'Ya existe como campo de texto. Recomiendo usarlo como está.'
        ];

        // 6. Última Compra Monto (no existe)
        $results['Última Compra Monto'] = $this->createFieldIfNotExists([
            'title' => 'Última Compra Monto',
            'type' => 'number',
            'description' => 'Monto de la última compra realizada',
            'decimal_places' => 2,
            'show_in_list' => true,
            'ordernum' => 33
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Proceso de verificación/creación de campos completado',
            'results' => $results,
            'config_suggestion' => [
                'fields' => [
                    'membresia_activa' => $results['Membresía Activa']['id'] ?? null,
                    'fecha_inicio_membresia' => $results['Fecha Inicio Membresía']['id'] ?? null,
                    'fecha_fin_membresia' => $results['Fecha Fin Membresía']['id'] ?? null,
                    'total_compras_farmacia' => '21', // ID existente
                    'total_compras_laboratorio' => '20', // ID existente
                    'ultima_compra_monto' => $results['Última Compra Monto']['id'] ?? null,
                ]
            ]
        ], 200, [], JSON_PRETTY_PRINT);
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
     * Método para obtener los IDs de todos los campos relevantes
     */
    public function getRelevantFields()
    {
        $allFields = $this->service->getCustomFields();
        
        $relevantTitles = [
            'Membresía Activa',
            'Fecha Inicio Membresía',
            'Fecha Fin Membresía',
            'Compras de Farmacia', // Nombre exacto del campo existente
            'Compras de Laboratorio', // Nombre exacto del campo existente
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
                'ACTIVECAMPAIGN_FIELD_TOTAL_FARMACIA' => $relevantFields['Compras de Farmacia']['id'] ?? '21',
                'ACTIVECAMPAIGN_FIELD_TOTAL_LABORATORIO' => $relevantFields['Compras de Laboratorio']['id'] ?? '20',
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