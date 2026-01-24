<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ActiveCampaignService;

class DiscoverActiveCampaignFields extends Command
{
    protected $signature = 'ac:discover-fields {--validate : Validar configuración actual} {--search= : Buscar campo específico}';
    protected $description = 'Descubrir campos personalizados de ActiveCampaign';

    public function handle(ActiveCampaignService $service)
    {
        if ($this->option('search')) {
            $searchTerm = $this->option('search');
            $this->info("Buscando campo: '{$searchTerm}'");
            
            $field = $service->findFieldByTitleOrTag($searchTerm);
            
            if ($field) {
                $this->info("✅ Campo encontrado:");
                $this->table(
                    ['Propiedad', 'Valor'],
                    [
                        ['ID', $field['id']],
                        ['Título', $field['title']],
                        ['Etiqueta', $field['perstag']],
                        ['Tipo', $field['type']],
                    ]
                );
            } else {
                $this->error("❌ Campo no encontrado");
            }
            
            return 0;
        }

        if ($this->option('validate')) {
            $this->info("🔍 Validando configuración actual...");
            
            $validation = $service->validateFieldConfiguration();
            
            $this->info("Resultados de validación:");
            
            foreach ($validation['validation_results'] as $fieldName => $result) {
                if ($result['status'] === 'ok') {
                    $this->info("✅ {$fieldName}: ID {$result['configured_id']} - '{$result['actual_title']}'");
                } else {
                    $this->error("❌ {$fieldName}: {$result['message']}");
                }
            }
            
            if (!empty($validation['unconfigured_fields'])) {
                $this->warn("\n📋 Campos no configurados en ActiveCampaign:");
                $this->table(
                    ['ID', 'Título', 'Etiqueta'],
                    array_map(function($field) {
                        return [$field['id'], $field['title'], $field['perstag']];
                    }, $validation['unconfigured_fields'])
                );
            }
            
            return 0;
        }

        $this->info("🔍 Descubriendo campos personalizados de ActiveCampaign...");
        
        $discovery = $service->discoverAndMapCustomFields();
        
        if (empty($discovery['available_fields'])) {
            $this->error("❌ No se pudieron obtener los campos personalizados");
            if (isset($discovery['error'])) {
                $this->error("Error: {$discovery['error']}");
            }
            return 1;
        }

        $this->info("✅ Se encontraron {$discovery['total_fields']} campos personalizados\n");
        
        // Mostrar tabla de campos
        $this->table(
            ['ID', 'Título', 'Etiqueta', 'Tipo'],
            array_map(function($field) {
                return [
                    $field['id'],
                    $field['title'],
                    $field['perstag'],
                    $field['type'],
                ];
            }, $discovery['available_fields'])
        );

        // Sugerir configuración basada en nombres
        $this->info("\n💡 Sugerencias de configuración para .env:");
        
        $suggestions = [];
        foreach ($discovery['available_fields'] as $field) {
            $title = $field['title'];
            $perstag = $field['perstag'];
            $id = $field['id'];
            
            // Generar nombre de variable basado en el título
            $envVar = 'ACTIVE_CAMPAIGN_FIELD_' . strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '_', $perstag ?: $title));
            
            $suggestions[] = [
                'Variable .env' => $envVar,
                'Valor' => $id,
                'Campo' => $title,
            ];
        }
        
        $this->table(
            ['Variable .env', 'Valor', 'Campo'],
            $suggestions
        );

        return 0;
    }
}